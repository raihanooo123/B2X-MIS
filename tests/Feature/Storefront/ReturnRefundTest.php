<?php

use App\Domain\Billing\PaymentGateway;
use App\Domain\Returns\ConsumerCancellations;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\ProofOfSending;
use App\Domain\Returns\RefundCalculator;
use App\Domain\Returns\ReturnInspection;
use App\Domain\Returns\ReturnReceipt;
use App\Domain\Returns\ReturnResolution;
use App\Models\CreditNote;
use App\Models\DeliveryZone;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\Rma;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCardGateway;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S6d — inspection and refund of a consumer cancellation
 * (05.4 §13.5–13.6): dispositions, unsealed hygiene items, diminished value,
 * the delivery refund, the credit note on the receipt, the refund to the
 * card or by bank transfer, never an account balance (C1–C5).
 *
 * Lines are £20.00 net, VAT 20%; the standard delivery is £6.50 net.
 */
beforeEach(function () {
    $this->withoutVite();
    Storage::fake((string) config('filesystems.default'));
    $this->gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);
    $this->user = User::factory()->create();
    $this->location = Location::factory()->default()->create();
});

function refundStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

/**
 * A consumer order, dispatched, paid in full and receipted.
 *
 * @param  list<Sku>  $skus  one line each, 1 pack of `$units` units, £20.00 net per line
 */
function refundOrder(array $skus = [], int $shippingNet = 650, int $standardNet = 650, string $gateway = 'stripe', int $units = 1): Order
{
    $zone = DeliveryZone::factory()->create(['transit_days' => 2]);
    $skus = $skus === [] ? [Sku::factory()->create()] : $skus;
    $goods = 2000 * count($skus);
    $shippingTax = intdiv($shippingNet * 2000 + 5000, 10000);
    $total = $goods + intdiv($goods * 2000, 10000) + $shippingNet + $shippingTax;

    $order = Order::factory()->create([
        'company_id' => null, 'user_id' => test()->user->id, 'status' => 'dispatched', 'delivery_zone_id' => $zone->id, 'delivery_method' => 'parcel',
        'subtotal_net_minor' => $goods, 'shipping_net_minor' => $shippingNet, 'shipping_tax_rate_bp' => 2000, 'shipping_tax_minor' => $shippingTax,
        'standard_shipping_net_minor' => $standardNet, 'tax_minor' => intdiv($goods * 2000, 10000) + $shippingTax, 'total_gross_minor' => $total,
        'payment_method' => $gateway === 'stripe' ? 'card' : 'bacs', 'payment_status' => 'paid',
    ]);
    foreach ($skus as $i => $sku) {
        $pack = Pack::factory()->for($sku)->create(['base_units' => $units]);
        OrderLine::factory()->forPack($pack, 1)->dispatched()->create(['order_id' => $order->id, 'line_no' => $i + 1, 'line_net_minor' => 2000, 'tax_rate_bp' => 2000, 'line_tax_minor' => 400]);
    }
    Shipment::factory()->dispatched()->create(['order_id' => $order->id, 'location_id' => test()->location->id, 'dispatched_at' => '2026-10-02 10:00:00+00']);
    Payment::factory()->create([
        'order_id' => $order->id, 'company_id' => null, 'gateway' => $gateway, 'gateway_reference' => $gateway === 'stripe' ? 'pi_'.Str::random(16) : null,
        'status' => 'captured', 'amount_minor' => $total,
    ]);
    Invoice::factory()->receipt()->paid()->create(['order_id' => $order->id, 'total_gross_minor' => $total, 'tax_minor' => $order->tax_minor, 'subtotal_net_minor' => $goods, 'shipping_net_minor' => $shippingNet]);

    return $order;
}

/** Cancel `$packs` (line_no => packs) on 8 October, receive what was asked, inspect as given. */
function refundRma(Order $order, array $packs, ?array $inspect = null): Rma
{
    $rma = (new ConsumerCancellations)->request($order->id, $packs, CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'), test()->user->id);
    $received = [];
    foreach ($rma->lines as $line) {
        $received[$line->line_no] = $line->requested_base_qty;
    }
    (new ReturnReceipt)->receive($rma->id, $received, refundStaff('warehouse')->id);

    $decisions = [];
    foreach ($rma->lines as $line) {
        $decisions[$line->line_no] = $inspect[$line->line_no] ?? ['restock' => $line->requested_base_qty, 'quarantine' => 0, 'write_off' => 0];
    }
    (new ReturnInspection)->inspect($rma->id, $decisions, refundStaff('warehouse')->id);

    return $rma->fresh();
}

function settle(Rma $rma): Rma
{
    return (new ReturnResolution)->resolve($rma->id, refundStaff('accounts')->id);
}

it('refunds goods and the standard delivery when nothing is kept, to the same card (C1)', function () {
    $order = refundOrder();
    $card = Payment::query()->where('order_id', $order->id)->sole();

    $rma = settle(refundRma($order, [1 => 1]));
    $refund = Payment::query()->where('type', 'refund')->sole();

    expect($rma->refund_net_minor)->toBe(2000)
        ->and($rma->refund_tax_minor)->toBe(400)
        ->and($rma->delivery_refund_net_minor)->toBe(650)
        ->and($rma->delivery_refund_tax_minor)->toBe(130)
        ->and($rma->refund_gross_minor)->toBe(3180)
        ->and($rma->status)->toBe('resolved')
        ->and($rma->resolution_type)->toBe('credit_note')
        ->and($refund->status)->toBe('captured')
        ->and($refund->amount_minor)->toBe(3180)
        ->and($refund->refunded_payment_id)->toBe($card->id)
        ->and($this->gateway->refunds)->toHaveCount(1)
        ->and($this->gateway->refunds[0]['intent'])->toBe($card->gateway_reference)
        ->and($this->gateway->refunds[0]['amount_minor'])->toBe(3180)
        ->and($card->fresh()->status)->toBe('refunded')
        ->and($order->fresh()->payment_status)->toBe('refunded')
        ->and(DB::table('notification_log')->where('notification_key', 'rma.resolved')->value('recipient'))->toBe(strtolower($this->user->email));
});

it('refunds no delivery while part of the order is kept (C2)', function () {
    $order = refundOrder([Sku::factory()->create(), Sku::factory()->create()]);

    $rma = settle(refundRma($order, [1 => 1]));

    expect($rma->refund_net_minor)->toBe(2000)
        ->and($rma->delivery_refund_net_minor)->toBe(0)
        ->and($rma->refund_gross_minor)->toBe(2400);
});

it('refunds an upgraded delivery at the standard charge only (C3)', function () {
    $order = refundOrder(shippingNet: 1200, standardNet: 650);

    $rma = settle(refundRma($order, [1 => 1]));

    expect($rma->delivery_refund_net_minor)->toBe(650)
        ->and($rma->delivery_refund_tax_minor)->toBe(130)
        ->and($rma->refund_gross_minor)->toBe(3180);
});

it('deducts diminished value with a reason (C4)', function () {
    $order = refundOrder();

    $rma = settle(refundRma($order, [1 => 1], [1 => ['restock' => 1, 'quarantine' => 0, 'write_off' => 0, 'diminished_value_minor' => 500, 'diminished_value_reason' => 'Box opened and used once']]));
    $line = $rma->lines()->sole();

    expect($line->line_refund_net_minor)->toBe(1500)
        ->and($line->line_refund_tax_minor)->toBe(300)
        ->and($line->diminished_value_reason)->toBe('Box opened and used once')
        ->and($rma->refund_gross_minor)->toBe(1500 + 300 + 650 + 130);
});

it('refuses a deduction without a reason, in the service and in the database (C5)', function () {
    $order = refundOrder();
    $rma = (new ConsumerCancellations)->request($order->id, [1 => 1], CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'), $this->user->id);
    (new ReturnReceipt)->receive($rma->id, [1 => 1], refundStaff('warehouse')->id);

    try {
        (new ReturnInspection)->inspect($rma->id, [1 => ['restock' => 1, 'quarantine' => 0, 'write_off' => 0, 'diminished_value_minor' => 500, 'diminished_value_reason' => '']], refundStaff('warehouse')->id);
        $this->fail('Expected the deduction to be refused.');
    } catch (ReturnActionRefusedException $e) {
        expect($e->reason)->toBe('diminished_reason_required');
    }

    $lineId = $rma->lines()->sole()->id;
    expect(fn () => DB::transaction(fn () => DB::table('rma_lines')->where('id', $lineId)->update(['diminished_value_minor' => 100, 'diminished_value_reason' => null])))
        ->toThrow(QueryException::class, 'rma_lines_diminished_chk');
});

it('sums refund gross exactly over 1,000 generated consumer returns', function () {
    mt_srand(20261004);
    for ($i = 0; $i < 1000; $i++) {
        $lines = [];
        for ($n = mt_rand(1, 5); $n > 0; $n--) {
            $goods = mt_rand(0, 1_000_000);
            $requested = mt_rand(1, 100);
            $lines[] = [
                'goods_net_minor' => $goods,
                'requested_base_qty' => $requested,
                'refundable_base_qty' => mt_rand(0, $requested + 5),
                'diminished_value_minor' => mt_rand(0, intdiv($goods, 3)),
                'tax_rate_bp' => [0, 500, 2000][mt_rand(0, 2)],
            ];
        }
        $calc = RefundCalculator::calculate($lines, mt_rand(0, 5000), [0, 2000][mt_rand(0, 1)]);

        $sum = array_sum(array_column($calc['lines'], 'net_minor')) + array_sum(array_column($calc['lines'], 'tax_minor')) + $calc['delivery_net_minor'] + $calc['delivery_tax_minor'];
        expect($calc['gross_minor'])->toBe($sum)
            ->and($calc['net_minor'])->toBeGreaterThanOrEqual(0)
            ->and(min(array_column($calc['lines'], 'net_minor')))->toBeGreaterThanOrEqual(0);
        foreach ($calc['lines'] as $j => $line) {
            expect($line['net_minor'])->toBeLessThanOrEqual($lines[$j]['goods_net_minor']);
        }
    }
});

it('does not refund an unsealed hygiene item sent back to the customer', function () {
    $order = refundOrder([Sku::factory()->nonRefundable('hygiene')->create()], units: 2);

    $rma = settle(refundRma($order, [1 => 1], [1 => ['restock' => 1, 'quarantine' => 0, 'write_off' => 0]]));
    $line = $rma->lines()->sole();

    expect($line->restocked_base_qty)->toBe(1)
        ->and($line->disposition_reason)->toContain('seal')
        ->and($line->line_refund_net_minor)->toBe(1000)
        ->and($rma->delivery_refund_net_minor)->toBe(0)
        ->and($rma->status)->toBe('partially_resolved');
});

it('restocks with a return_in movement; quarantine and write-off move no stock', function () {
    $order = refundOrder([Sku::factory()->create(), Sku::factory()->create()], units: 2);
    $skuIds = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->pluck('sku_id');

    refundRma($order, [1 => 1, 2 => 1], [1 => ['restock' => 2, 'quarantine' => 0, 'write_off' => 0], 2 => ['restock' => 0, 'quarantine' => 1, 'write_off' => 1]]);

    expect(StockMovement::query()->where('movement_type', 'return_in')->count())->toBe(1)
        ->and(StockMovement::query()->where('movement_type', 'return_in')->value('reference_type'))->toBe('rma')
        ->and((int) StockLevel::query()->where('sku_id', $skuIds[0])->where('location_id', $this->location->id)->whereNull('batch_id')->value('on_hand_base_qty'))->toBe(2)
        ->and(StockLevel::query()->where('sku_id', $skuIds[1])->exists())->toBeFalse();
});

it('issues a credit note on the receipt, with no company, and never an account balance', function () {
    $order = refundOrder();
    $receipt = Invoice::query()->where('order_id', $order->id)->sole();

    $rma = settle(refundRma($order, [1 => 1]));
    $credit = CreditNote::query()->sole();

    expect($credit->company_id)->toBeNull()
        ->and($credit->invoice_id)->toBe($receipt->id)
        ->and($credit->order_id)->toBe($order->id)
        ->and($credit->rma_id)->toBe($rma->id)
        ->and($credit->reason)->toBe('return')
        ->and($credit->total_gross_minor)->toBe($rma->refund_gross_minor)
        ->and($credit->subtotal_net_minor)->toBe($rma->refund_net_minor + $rma->delivery_refund_net_minor)
        ->and($credit->tax_minor)->toBe($rma->refund_tax_minor + $rma->delivery_refund_tax_minor)
        ->and($credit->subtotal_net_minor + $credit->tax_minor)->toBe($credit->total_gross_minor)
        ->and($rma->credit_note_id)->toBe($credit->id)
        // The money goes back the way it came: a refund row on the card payment, no company to hold a balance.
        ->and(Payment::query()->where('type', 'refund')->whereNull('company_id')->count())->toBe(1)
        ->and(DB::table('account_credit_movements')->count())->toBe(0);
});

it('leaves a BACS refund for accounts to pay and record', function () {
    $order = refundOrder(gateway: 'bacs');
    $rma = settle(refundRma($order, [1 => 1]));
    $refund = Payment::query()->where('type', 'refund')->sole();

    expect($refund->gateway)->toBe('bacs')
        ->and($refund->status)->toBe('pending')
        ->and($this->gateway->refunds)->toBe([]);

    $this->actingAs(refundStaff('accounts'))
        ->postJson("/api/v1/warehouse/returns/{$rma->public_id}/bank-refund", ['reference' => 'FPS 123456'])
        ->assertOk()->assertJsonPath('data.refund.status', 'captured');
    expect($refund->fresh()->status)->toBe('captured')
        ->and($refund->fresh()->gateway_reference)->toBe('FPS 123456');
});

it('marks a refused card refund failed, tells accounts, and takes their bank transfer instead', function () {
    $accounts = refundStaff('accounts');
    $order = refundOrder();
    $this->gateway->failRefund = true;

    $rma = settle(refundRma($order, [1 => 1]));
    $card = Payment::query()->where('type', 'refund')->sole();

    expect($card->status)->toBe('failed')
        ->and($rma->refund_payment_id)->toBe($card->id)
        ->and(DB::table('notification_log')->where('notification_key', 'refund.failed')->where('user_id', $accounts->id)->exists())->toBeTrue();

    $this->actingAs($accounts)->postJson("/api/v1/warehouse/returns/{$rma->public_id}/bank-refund", ['reference' => 'FPS 987654'])->assertOk();

    $bank = Payment::query()->where('type', 'refund')->where('gateway', 'bacs')->sole();
    expect($bank->status)->toBe('captured')
        ->and($bank->amount_minor)->toBe(3180)
        ->and($card->fresh()->status)->toBe('failed')
        ->and($rma->fresh()->refund_payment_id)->toBe($bank->id)
        ->and($order->fresh()->payment_status)->toBe('refunded');
});

it('refunds on proof of sending without waiting for the parcel', function () {
    $order = refundOrder();
    $rma = (new ConsumerCancellations)->request($order->id, [1 => 1], CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'), $this->user->id);
    (new ProofOfSending)->upload($rma->id, UploadedFile::fake()->image('postage.jpg'), $this->user->id);

    $settled = settle($rma);

    expect($settled->status)->toBe('resolved')
        ->and($settled->refund_gross_minor)->toBe(3180)
        ->and(StockMovement::query()->where('movement_type', 'return_in')->count())->toBe(0)
        ->and(Payment::query()->where('type', 'refund')->sole()->status)->toBe('captured');
});

it('lets the warehouse inspect and accounts settle, and not the other way round', function () {
    $order = refundOrder();
    $rma = (new ConsumerCancellations)->request($order->id, [1 => 1], CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'), $this->user->id);
    (new ReturnReceipt)->receive($rma->id, [1 => 1], refundStaff('warehouse')->id);
    $inspect = fn (User $u) => $this->actingAs($u)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson("/api/v1/warehouse/returns/{$rma->public_id}/inspect", ['lines' => [['line_no' => 1, 'restock' => 1, 'quarantine' => 0, 'write_off' => 0]]]);
    $resolve = fn (User $u) => $this->actingAs($u)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson("/api/v1/warehouse/returns/{$rma->public_id}/resolve", []);

    $inspect(refundStaff('accounts'))->assertForbidden();
    $inspect(refundStaff('warehouse'))->assertOk()->assertJsonPath('data.status', 'inspected');
    $resolve(refundStaff('warehouse'))->assertForbidden();
    $resolve(refundStaff('accounts'))->assertOk()->assertJsonPath('data.refund.gross_minor', 3180);
});

it('books in and restocks goods arriving after settlement on proof without reopening money', function () {
    $order = refundOrder();
    $rma = (new ConsumerCancellations)->request($order->id, [1 => 1], CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'), $this->user->id);
    (new ProofOfSending)->upload($rma->id, UploadedFile::fake()->image('postage.jpg'), $this->user->id);
    $settled = settle($rma);
    $snapshot = [$settled->credit_note_id, $settled->refund_payment_id, $settled->refund_gross_minor, $settled->resolved_at->toIso8601String(), $settled->refund_due_on->toDateString()];
    $warehouse = refundStaff('warehouse');
    $this->actingAs($warehouse)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson("/api/v1/warehouse/returns/{$rma->public_id}/receive", ['lines' => [['line_no' => 1, 'received_base_qty' => 1]]])
        ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.can_inspect', true);
    $this->actingAs($warehouse)->withHeader('Idempotency-Key', (string) Str::ulid())
        ->postJson("/api/v1/warehouse/returns/{$rma->public_id}/inspect", ['lines' => [['line_no' => 1, 'restock' => 1, 'quarantine' => 0, 'write_off' => 0]]])
        ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.can_inspect', false);
    $fresh = $rma->fresh();
    expect([$fresh->credit_note_id, $fresh->refund_payment_id, $fresh->refund_gross_minor, $fresh->resolved_at->toIso8601String(), $fresh->refund_due_on->toDateString()])->toBe($snapshot)
        ->and(StockMovement::query()->where('movement_type', 'return_in')->count())->toBe(1)
        ->and(OrderLine::query()->where('order_id', $order->id)->sole()->returned_base_qty)->toBe(1)
        ->and($fresh->lines()->sole()->line_refund_net_minor)->toBe(2000);
    expect(fn () => (new ReturnReceipt)->receive($rma->id, [1 => 1], $warehouse->id))->toThrow(ReturnActionRefusedException::class);
    expect(fn () => (new ReturnInspection)->inspect($rma->id, [1 => ['restock' => 1, 'quarantine' => 0, 'write_off' => 0]], $warehouse->id))->toThrow(ReturnActionRefusedException::class);
    expect(fn () => settle($rma))->toThrow(ReturnActionRefusedException::class);
    expect(CreditNote::query()->count())->toBe(1)
        ->and(Payment::query()->where('type', 'refund')->count())->toBe(1)
        ->and(StockMovement::query()->where('movement_type', 'return_in')->count())->toBe(1)
        ->and($this->gateway->refunds)->toHaveCount(1);
});
