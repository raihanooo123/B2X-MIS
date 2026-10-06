<?php

use App\Domain\Billing\CardIntent;
use App\Domain\Credit\ApprovedOrderPayment;
use App\Domain\Credit\CreditExpiry;
use App\Domain\Credit\TradeApprovals;
use App\Models\CollectionBooking;
use App\Models\CollectionSlot;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditHold;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * 05.2 §18.2, 02 §31.1 (corrected 2026-10-06): every write to an existing
 * trade order locks companies → orders → collection_slots → stock_levels,
 * as dispatch, cancellation and cash at collection do, then payments →
 * invoices → order_approval_requests → credit_holds → collection_bookings.
 * The order row after stock_levels would deadlock against them.
 */
uses(RefreshDatabase::class);

const CLO_HEAD = ['companies', 'orders', 'collection_slots', 'stock_levels'];
const CLO_GLOBAL = ['companies', 'orders', 'collection_slots', 'stock_levels', 'payments', 'invoices', 'order_approval_requests', 'credit_holds', 'collection_bookings'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC'));
    $this->location = Location::factory()->default()->create();
    $this->sku = Sku::factory()->create(['is_stock_tracked' => true, 'tracking_mode' => 'none']);
    $this->pack = Pack::factory()->for($this->sku)->create(['base_units' => 1]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
});

/**
 * A trade collection order awaiting a decision. Funded: slot booked,
 * 10 units allocated, credit held. Unfunded (a credit shortfall): the
 * booking keeps the slot without capacity, nothing allocated or held.
 *
 * @return array{order: Order, request: OrderApprovalRequest, buyer: User, approver: User}
 */
function cloOrder(string $kind = 'buyer_limit', bool $funded = true, string $method = 'on_account', string $status = 'awaiting_approval', array $request = []): array
{
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 1_000_000]);
    $buyer = User::factory()->create();
    $approver = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id, 'role' => 'buyer']);
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $approver->id, 'role' => 'approver']);

    $order = Order::factory()->create([
        'company_id' => $company->id, 'user_id' => $buyer->id, 'status' => $status, 'confirmed_at' => null,
        'fulfilment_type' => 'collection', 'payment_method' => $method, 'payment_status' => $method === 'on_account' ? 'on_account' : 'unpaid',
        'subtotal_net_minor' => 1000, 'tax_minor' => 200, 'total_gross_minor' => 1200, 'placed_at' => now()->subHour(),
    ]);
    $line = OrderLine::factory()->forPack(test()->pack, 10)->create(['order_id' => $order->id, 'line_net_minor' => 1000, 'line_tax_minor' => 200, 'line_gross_minor' => 1200]);
    $slot = CollectionSlot::query()->create([
        'location_id' => test()->location->id, 'slot_date' => '2026-10-08', 'start_time' => '09:00', 'end_time' => '12:00',
        'capacity' => 5, 'booked_count' => $funded ? 1 : 0, 'status' => 'open',
    ]);
    CollectionBooking::query()->create(['collection_slot_id' => $slot->id, 'order_id' => $order->id, 'company_id' => $company->id, 'status' => $funded ? 'booked' : 'cancelled']);

    if ($funded) {
        StockAllocation::factory()->create(['order_line_id' => $line->id, 'sku_id' => test()->sku->id, 'location_id' => test()->location->id, 'base_qty' => 10]);
        DB::table('stock_levels')->where('sku_id', test()->sku->id)->update(['allocated_base_qty' => 10]);
        if ($method === 'on_account') {
            CreditHold::factory()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => 1200, 'status' => 'held']);
            DB::table('companies')->where('id', $company->id)->update(['credit_held_minor' => 1200]);
        }
    }

    $approval = OrderApprovalRequest::query()->create([
        'company_id' => $company->id, 'order_id' => $order->id, 'requested_by_user_id' => $buyer->id, 'approval_kind' => $kind,
        'status' => 'pending', 'order_gross_minor' => 1200, 'requested_at' => now()->subHour(), 'expires_at' => now()->addHours(47),
    ] + $request);

    return ['order' => $order, 'request' => $approval, 'buyer' => $buyer, 'approver' => $approver];
}

/** Every `FOR UPDATE` table, in order, while $work runs. @return list<string> */
function cloLocks(callable $work): array
{
    $tables = [];
    DB::listen(function (QueryExecuted $query) use (&$tables): void {
        if (stripos($query->sql, 'for update') !== false && preg_match('/from\s+"?([a-z_]+)"?/i', $query->sql, $match) === 1) {
            $tables[] = strtolower($match[1]);
        }
    });
    $work();

    return $tables;
}

/** First-lock order: the head is exactly companies → orders → collection_slots → stock_levels, and nothing runs backwards. */
function cloAssertOrder(array $tables): void
{
    $seen = array_values(array_unique(array_values(array_filter($tables, fn (string $t) => in_array($t, CLO_GLOBAL, true)))));

    expect(array_values(array_intersect($seen, CLO_HEAD)))->toBe(CLO_HEAD, 'Head locks: '.implode(' → ', $seen));
    $ranks = array_map(fn (string $t) => array_search($t, CLO_GLOBAL, true), $seen);
    $sorted = $ranks;
    sort($sorted);
    expect($ranks)->toBe($sorted, 'Locks out of order: '.implode(' → ', $seen));
}

it('locks companies → orders → collection_slots → stock_levels when approving', function () {
    ['request' => $request, 'approver' => $approver, 'order' => $order] = cloOrder();

    cloAssertOrder(cloLocks(fn () => (new TradeApprovals)->decide($request->id, $approver, true)));
    expect($order->fresh()->status)->toBe('confirmed');
});

it('locks companies → orders → collection_slots → stock_levels when rejecting', function () {
    ['request' => $request, 'approver' => $approver, 'order' => $order] = cloOrder();

    cloAssertOrder(cloLocks(fn () => (new TradeApprovals)->decide($request->id, $approver, false, 'Over budget')));
    expect($order->fresh()->status)->toBe('cancelled')
        ->and(CollectionSlot::query()->value('booked_count'))->toBe(0);
});

it('takes the order before slot and stock when accounts fund a credit shortfall', function () {
    ['request' => $request, 'order' => $order] = cloOrder('credit_exception', funded: false);
    $accounts = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::factory()->create(['code' => 'accounts'])->id, 'user_id' => $accounts->id]);

    cloAssertOrder(cloLocks(fn () => (new TradeApprovals)->decide($request->id, $accounts, true)));
    expect($order->fresh()->status)->toBe('confirmed')
        ->and(StockAllocation::query()->count())->toBe(1)
        ->and(CollectionSlot::query()->value('booked_count'))->toBe(1);
});

it('locks companies → orders → collection_slots → stock_levels when the reaper expires an order', function () {
    ['order' => $order] = cloOrder(request: ['requested_at' => now()->subHours(49), 'expires_at' => now()->subHour()]);

    cloAssertOrder(cloLocks(fn () => expect((new CreditExpiry)->sweep())->toBe(1)));
    expect($order->fresh()->status)->toBe('cancelled');
});

it('locks companies → orders → collection_slots → stock_levels when an approved order is paid', function () {
    ['order' => $order, 'buyer' => $buyer, 'approver' => $approver] = cloOrder(method: 'card', status: 'pending_payment', request: ['status' => 'approved', 'decided_at' => now()->subMinutes(5)]);
    OrderApprovalRequest::query()->where('order_id', $order->id)->update(['decided_by_user_id' => $approver->id]);

    cloAssertOrder(cloLocks(fn () => (new ApprovedOrderPayment)->confirm($order->id, $buyer, new CardIntent('pi_locktest123456', null, 'requires_capture', 1200, 'GBP'))));
    expect($order->fresh()->status)->toBe('confirmed');
});
