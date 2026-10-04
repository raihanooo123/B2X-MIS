<?php

use App\Domain\Notifications\Notices\OrderConfirmed;
use App\Domain\Notifications\Notices\RmaApproved;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Domain\Returns\CancellationEligibility;
use App\Domain\Returns\ConsumerCancellations;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Domain\Returns\PossessionDay;
use App\Models\Batch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Rma;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentLineBatch;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\TermsVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliveryZoneSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 05.15 slice S6b — a consumer cancels items of a dispatched order (05.4
 * §13.3): possession day, the 14-day window, statutory exclusions, quantity
 * already returned, recall, automatic approval, no fee (C6–C9); and A9, the
 * return cost of a pallet consignment (02 §27).
 *
 * 2 October 2026 is a Friday.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

/**
 * A consumer order dispatched at `$dispatchedAt` (UTC) to a zone with
 * `$transitDays`, with one line per SKU of 3 packs of 1, £10.00 net each.
 *
 * @param  list<Sku>  $skus
 * @return array{0: Order, 1: list<OrderLine>}
 */
function cxOrder(array $skus = [], ?int $transitDays = 2, array $dispatchedAt = ['2026-10-02 10:00:00+00'], array $orderAttributes = []): array
{
    $zone = DeliveryZone::factory()->create(['transit_days' => $transitDays]);
    $order = Order::factory()->create($orderAttributes + [
        'company_id' => null,
        'user_id' => test()->user->id,
        'status' => 'dispatched',
        'delivery_zone_id' => $zone->id,
        'delivery_method' => 'parcel',
    ]);

    $lines = [];
    foreach ($skus === [] ? [Sku::factory()->create()] : $skus as $i => $sku) {
        $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);
        $lines[] = OrderLine::factory()->forPack($pack, 3)->dispatched()->create([
            'order_id' => $order->id, 'line_no' => $i + 1, 'unit_price_net_e4' => 100000, 'line_net_minor' => 3000, 'tax_rate_bp' => 2000, 'line_tax_minor' => 600,
        ]);
    }
    foreach ($dispatchedAt as $at) {
        Shipment::factory()->dispatched()->create(['order_id' => $order->id, 'dispatched_at' => $at]);
    }

    return [$order, $lines];
}

function cxRequest(Order $order, array $packs, string $at = '2026-10-10 12:00:00'): Rma
{
    return (new ConsumerCancellations)->request($order->id, $packs, CarbonImmutable::parse($at, 'Europe/London'), test()->user->id);
}

function cxRefusal(callable $request): ?string
{
    try {
        $request();
    } catch (CancellationRequestRejectedException $e) {
        return $e->reason;
    }

    return null;
}

it('takes possession as the last dispatch + transit working days + 1 day (C7)', function () {
    [$order] = cxOrder(transitDays: 2);
    $day = PossessionDay::for($order);

    expect($day->date->toDateString())->toBe('2026-10-07')
        ->and($day->basis)->toBe('estimated_from_dispatch')
        ->and($day->lastDay()->toDateString())->toBe('2026-10-21');
});

it('uses 5 transit days for a zone without any, or the configured default', function () {
    [$order] = cxOrder(transitDays: null);
    expect(PossessionDay::for($order)->date->toDateString())->toBe('2026-10-10');

    SystemConfiguration::factory()->create(['config_key' => 'returns.consumer_default_transit_days', 'value_type' => 'int', 'value_int' => 1, 'value_text' => null]);
    expect(PossessionDay::for($order)->date->toDateString())->toBe('2026-10-06');
});

it('runs the window from the last part of a multi-part delivery, and not before it arrives', function () {
    [$order] = cxOrder(transitDays: 2, dispatchedAt: ['2026-10-02 10:00:00+00', '2026-10-05 10:00:00+00']);
    expect(PossessionDay::for($order)->date->toDateString())->toBe('2026-10-08');

    $order->update(['status' => 'part_dispatched']);
    $eligibility = CancellationEligibility::for($order->fresh(), CarbonImmutable::parse('2026-10-06'));
    expect($eligibility->available)->toBeFalse()
        ->and($eligibility->reason)->toBe('part_dispatched');
});

it('accepts a request on the 14th day after possession and refuses one a minute later (C8)', function () {
    [$order] = cxOrder(transitDays: 2);

    $rma = cxRequest($order, [1 => 1], '2026-10-21 23:59:00');
    expect($rma->status)->toBe('awaiting_goods');

    [$late] = cxOrder(transitDays: 2);
    try {
        cxRequest($late, [1 => 1], '2026-10-22 00:00:00');
        $this->fail('Expected the request to be refused.');
    } catch (CancellationRequestRejectedException $e) {
        expect($e->reason)->toBe('window_closed')
            ->and($e->getMessage())->toContain('21 October 2026');
    }
});

it('approves at once, with no fee, the notification time, possession day and a 14-day return deadline', function () {
    [$order] = cxOrder();

    $rma = cxRequest($order, [1 => 2], '2026-10-10 12:00:00');
    $line = $rma->lines->sole();

    expect($rma->rma_number)->toBe('RMA-000001')
        ->and($rma->company_id)->toBeNull()
        ->and($rma->status)->toBe('awaiting_goods')
        ->and($rma->return_reason)->toBe('consumer_cancellation')
        ->and($rma->approved_at)->not->toBeNull()
        ->and($rma->possession_on->toDateString())->toBe('2026-10-07')
        ->and($rma->possession_basis)->toBe('estimated_from_dispatch')
        ->and($rma->return_by_date->toDateString())->toBe('2026-10-24')
        ->and($rma->return_method)->toBe('customer_carriage')
        ->and($rma->carriage_payer)->toBe('customer')
        ->and((int) DB::table('rmas')->where('id', $rma->id)->value('restocking_fee_minor'))->toBe(0)
        ->and($line->requested_pack_qty)->toBe(2)
        ->and($line->requested_base_qty)->toBe(2)
        ->and($line->line_goods_net_minor)->toBe(2000)
        ->and($rma->goods_net_minor)->toBe(2000)
        ->and(DB::table('notification_log')->where('notification_key', 'rma.approved')->value('recipient'))->toBe(strtolower($this->user->email));

    $mail = (new RmaApproved($rma->id))->content(Recipient::user($this->user));
    expect(implode(' ', $mail->paragraphs))->toContain('You pay the cost of posting them back')
        ->and(implode(' ', $mail->paragraphs))->toContain('RMA-000001');
});

it('caps a second request at what earlier returns have not already taken', function () {
    [$order] = cxOrder();
    cxRequest($order, [1 => 2]);

    expect(cxRefusal(fn () => cxRequest($order, [1 => 2])))->toBe('quantity_exceeds_returnable')
        ->and(cxRequest($order, [1 => 1])->lines->sole()->requested_base_qty)->toBe(1)
        // All 3 packs are now held by the two returns: nothing is left on the order.
        ->and(cxRefusal(fn () => cxRequest($order, [1 => 1])))->toBe('nothing_returnable');
});

it('refuses bespoke goods, warns about sealed hygiene goods, and allows trade-only exclusions (C9)', function () {
    [$order] = cxOrder([
        Sku::factory()->nonRefundable('bespoke')->create(),
        Sku::factory()->nonRefundable('hygiene')->create(),
        Sku::factory()->nonRefundable('consumable')->create(),
        Sku::factory()->nonRefundable('electrical_sealed')->create(),
    ]);
    $lines = CancellationEligibility::for($order, CarbonImmutable::parse('2026-10-10'))->lines;

    expect($lines[1]['eligible'])->toBeFalse()
        ->and($lines[1]['refusal'])->toContain('specification')
        ->and($lines[2]['eligible'])->toBeTrue()
        ->and($lines[2]['notice'])->toContain('seal unbroken')
        ->and($lines[3]['eligible'])->toBeTrue()
        ->and($lines[4]['eligible'])->toBeTrue()
        ->and(cxRefusal(fn () => cxRequest($order, [1 => 1])))->toBe('line_not_cancellable');
});

it('lets a recalled batch be cancelled after the window has closed', function () {
    [$order, $lines] = cxOrder([Sku::factory()->create(), Sku::factory()->create()]);
    $shipment = Shipment::query()->where('order_id', $order->id)->sole();
    $shipmentLine = ShipmentLine::factory()->create(['shipment_id' => $shipment->id, 'order_line_id' => $lines[0]->id, 'dispatched_base_qty' => 3]);
    ShipmentLineBatch::factory()->create(['shipment_line_id' => $shipmentLine->id, 'batch_id' => Batch::factory()->recalled('RC-1')->create(['sku_id' => $lines[0]->sku_id])->id, 'base_qty' => 3]);

    $eligibility = CancellationEligibility::for($order, CarbonImmutable::parse('2026-12-01'));
    expect($eligibility->available)->toBeTrue()
        ->and($eligibility->lines[1]['eligible'])->toBeTrue()
        ->and($eligibility->lines[2]['eligible'])->toBeFalse();
});

it('makes a fee or carriage recharge on a consumer return impossible to persist (C6)', function () {
    [$order] = cxOrder();
    $rma = cxRequest($order, [1 => 1]);

    // Each violation in its own savepoint: one failed statement aborts the
    // surrounding test transaction, and the next would never reach its constraint.
    expect(fn () => DB::transaction(fn () => DB::table('rmas')->where('id', $rma->id)->update(['restocking_fee_minor' => 2500])))->toThrow(QueryException::class, 'rmas_consumer_fee_chk')
        ->and(fn () => DB::transaction(fn () => DB::table('rmas')->where('id', $rma->id)->update(['carriage_recharge_minor' => 500])))->toThrow(QueryException::class, 'rmas_consumer_fee_chk')
        ->and(fn () => DB::transaction(fn () => DB::table('rmas')->where('id', $rma->id)->update(['cancellation_notified_at' => null])))->toThrow(QueryException::class, 'rmas_cancellation_chk');
});

it('takes requests from the order page, signed in or by guest link, but never for a trade order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Europe/London'));
    [$order] = cxOrder();

    $this->actingAs($this->user)->get(route('orders.confirmation', $order->public_id))
        ->assertInertia(fn ($page) => $page->where('order.cancellation.available', true)->where('order.cancellation.last_day', '2026-10-21'));
    $this->actingAs($this->user)->from(route('orders.confirmation', $order->public_id))
        ->post(route('orders.cancel-items', $order->public_id), ['lines' => [['line_no' => 1, 'pack_qty' => 1]]])
        ->assertSessionHas('status');
    expect(Rma::query()->count())->toBe(1);

    [$guestOrder] = cxOrder(orderAttributes: ['user_id' => null, 'guest_email' => 'guest@example.com']);
    $url = GuestOrderLink::url($guestOrder);
    $this->from($url)->post("{$url}/cancel-items", ['lines' => [['line_no' => 1, 'pack_qty' => 5]]])->assertSessionHasErrors('lines.1');
    $this->from($url)->post("{$url}/cancel-items", ['lines' => [['line_no' => 1, 'pack_qty' => 3]]])->assertSessionHas('status');
    expect(Rma::query()->where('order_id', $guestOrder->id)->sole()->requested_by_user_id)->toBeNull();

    $company = Company::factory()->create();
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id]);
    [$trade] = cxOrder(orderAttributes: ['company_id' => $company->id, 'user_id' => $buyer->id]);
    $this->actingAs($buyer)->post(route('orders.cancel-items', $trade->public_id), ['lines' => [['line_no' => 1, 'pack_qty' => 1]]])->assertForbidden();
    $this->travelBack();
});

/*
 * A9 — the return cost of a pallet consignment (02 §27).
 */

function palletSetup(int $unitPriceE4): void
{
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $location = Location::factory()->default()->create();
    $baseList = PriceList::factory()->create(['scope' => 'base']);
    $taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    test()->sku = Sku::factory()->create(['tax_class_id' => $taxClass->id]);
    test()->seed(DeliveryZoneSeeder::class);
    Pack::factory()->for(test()->sku)->create(['base_units' => 1, 'gross_weight_g' => 500]);
    PriceListItem::factory()->for($baseList, 'priceList')->for(test()->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $unitPriceE4]);
    StockLevel::factory()->for(test()->sku)->for($location)->create(['on_hand_base_qty' => 200, 'allocated_base_qty' => 0]);
    test()->terms = TermsVersion::factory()->sale()->create();
}

/** 70 × 500 g = 35 kg: over the 30 kg parcel limit, so a pallet. */
function palletOrder(User $user, int $packs = 70): array
{
    test()->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => test()->sku->public_id, 'pack_qty' => $packs])->assertSuccessful();
    $preview = test()->actingAs($user)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB', 'delivery_postcode' => 'E1 6AN'])->assertOk()->json();

    test()->actingAs($user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson('/api/v1/checkout', [
        'payment_method' => 'bacs',
        'expected_total_gross_minor' => $preview['total_gross_minor'],
        'terms_version_id' => test()->terms->id,
        'delivery_address' => ['contact_name' => 'Sam Lee', 'line1' => '1 High Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country_code' => 'GB'],
    ])->assertCreated();

    return [Order::query()->latest('id')->firstOrFail(), $preview];
}

it('saves the pallet return cost at placement, shows it before paying and in the confirmation email', function () {
    palletSetup(12345);
    [$order, $preview] = palletOrder($this->user);

    expect($order->delivery_method)->toBe('pallet')
        ->and($order->return_cost_estimate_gross_minor)->toBe($order->shipping_net_minor + $order->shipping_tax_minor)
        ->and($preview['return_estimate']['estimate_gross_minor'])->toBe($order->return_cost_estimate_gross_minor)
        ->and($preview['return_estimate']['statement'])->toContain('cannot be returned by post');

    $sections = (new OrderConfirmed($order->id))->content(Recipient::user($this->user))->sections;
    $returning = collect($sections)->firstWhere('heading', 'Returning goods');
    expect($returning['paragraphs'][0])->toContain('which we estimate at');
});

it('saves a pallet return cost even when delivery was free', function () {
    palletSetup(100000); // £10 × 70 = £700, over the £500 carriage-paid threshold
    [$order] = palletOrder($this->user);

    expect($order->shipping_net_minor)->toBe(0)
        ->and($order->return_cost_estimate_gross_minor)->toBeGreaterThan(0);
});

it('saves no return cost for a parcel or a trade order', function () {
    palletSetup(12345);
    [$parcel, $preview] = palletOrder($this->user, packs: 3);
    expect($parcel->delivery_method)->toBe('parcel')
        ->and($parcel->return_cost_estimate_gross_minor)->toBeNull()
        ->and($preview['return_estimate'])->toBeNull();

    expect(fn () => DB::table('orders')->where('id', $parcel->id)->update(['company_id' => Company::factory()->create()->id, 'return_cost_estimate_gross_minor' => 100]))
        ->toThrow(QueryException::class, 'orders_return_cost_estimate_chk');
});

it('states the pallet return cost, or our collection, when a cancellation is approved', function () {
    [$withEstimate] = cxOrder(orderAttributes: ['delivery_method' => 'pallet', 'return_cost_estimate_gross_minor' => 5760]);
    $rma = cxRequest($withEstimate, [1 => 1]);
    expect(implode(' ', (new RmaApproved($rma->id))->content(Recipient::user($this->user))->paragraphs))->toContain('£57.60');

    [$collect] = cxOrder(orderAttributes: ['delivery_method' => 'pallet', 'return_cost_estimate_gross_minor' => null]);
    $collected = cxRequest($collect, [1 => 1]);
    expect($collected->return_method)->toBe('collection')
        ->and($collected->carriage_payer)->toBe('us')
        ->and($collected->refund_due_on->toDateString())->toBe('2026-10-24')
        ->and(implode(' ', (new RmaApproved($collected->id))->content(Recipient::user($this->user))->paragraphs))->toContain('collect them, at our cost');
});
