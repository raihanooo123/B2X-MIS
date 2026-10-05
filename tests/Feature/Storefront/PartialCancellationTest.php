<?php

use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\PartialCancellations;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** @return array{Order, OrderLine, User} */
function partialCase(bool $trade = false): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = $trade ? Company::factory()->create() : null;
    if ($company !== null) {
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'buyer']);
    }

    $order = Order::factory()->create([
        'company_id' => $company?->id, 'user_id' => $user->id, 'placed_by_user_id' => $user->id,
        'subtotal_net_minor' => 3001, 'tax_minor' => 601, 'total_gross_minor' => 3602,
    ]);
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);
    $line = OrderLine::factory()->forPack($pack, 3)->create([
        'order_id' => $order->id, 'line_no' => 1, 'line_net_minor' => 3001,
        'line_tax_minor' => 601, 'line_gross_minor' => 3602, 'applied_break_qty' => $trade ? 3 : null,
    ]);

    return [$order, $line, $user];
}

it('cancels whole packs without repricing and records cumulative penny-exact shares', function () {
    [$order, $line, $user] = partialCase();
    $service = new PartialCancellations;
    $first = $service->cancel($order->id, [1 => 1], 'customer', $user->id, CarbonImmutable::now(), clientToken: 'first');
    $second = $service->cancel($order->id, [1 => 1], 'customer', $user->id, CarbonImmutable::now(), clientToken: 'second');

    expect(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(2)
        ->and(DB::table('order_lines')->where('id', $line->id)->value('line_net_minor'))->toBe(3001)
        ->and(DB::table('order_cancellation_lines')->where('order_cancellation_id', $first->id)->value('cancelled_base_qty'))->toBe(1)
        ->and(DB::table('order_cancellation_lines')->where('order_cancellation_id', $second->id)->value('cancelled_base_qty'))->toBe(1)
        ->and($first->cancelled_net_minor + $second->cancelled_net_minor + PartialCancellations::billable(3001, 2, 3))->toBe(3001)
        ->and($first->cancelled_tax_minor + $second->cancelled_tax_minor + PartialCancellations::billable(601, 2, 3))->toBe(601);
});

it('returns the first cancellation on an idempotent retry', function () {
    [$order, $line, $user] = partialCase();
    $service = new PartialCancellations;
    $first = $service->cancel($order->id, [1 => 1], 'customer', $user->id, CarbonImmutable::now(), clientToken: 'same');
    $again = $service->cancel($order->id, [1 => 1], 'customer', $user->id, CarbonImmutable::now(), clientToken: 'same');

    expect($again->id)->toBe($first->id)
        ->and(DB::table('order_cancellations')->where('order_id', $order->id)->count())->toBe(1)
        ->and(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(1);
});

it('refuses a trade customer going below the applied break but permits full-line cancellation', function () {
    [$order, $line, $user] = partialCase(true);
    try {
        (new PartialCancellations)->cancel($order->id, [1 => 1], 'customer', $user->id, CarbonImmutable::now(), reasonDetail: 'No longer needed');
        test()->fail('Expected break-quantity refusal.');
    } catch (OrderNotCancellableException $error) {
        expect($error->reason)->toBe('below_break_quantity');
    }
    expect(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(0);

    (new PartialCancellations)->cancel($order->id, [1 => 3], 'customer', $user->id, CarbonImmutable::now(), reasonDetail: 'No longer needed');
    expect(DB::table('orders')->where('id', $order->id)->value('status'))->toBe('cancelled');
});

it('allows a consumer below a trade break and denies an unrelated signed-in customer', function () {
    [$order, $line, $owner] = partialCase();
    DB::table('order_lines')->where('id', $line->id)->update(['applied_break_qty' => 3]);
    (new PartialCancellations)->cancel($order->id, [1 => 1], 'customer', $owner->id, CarbonImmutable::now());
    expect(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(1);

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($stranger)->withHeader('Idempotency-Key', 'stranger')->post(route('orders.cancel-undispatched-items', $order->public_id), [
        'lines' => [['line_no' => 1, 'pack_qty' => 1]],
    ])->assertForbidden();
    expect(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(1);
});

it('requires accounts or admin staff to record a below-break override with a reason and audit entry', function () {
    [$order, $line] = partialCase(true);
    $staff = User::factory()->withTwoFactor()->create();
    $role = Role::query()->where('code', 'accounts')->first() ?? Role::factory()->create(['code' => 'accounts']);
    RoleUser::create(['role_id' => $role->id, 'user_id' => $staff->id]);
    $url = route('staff.order-cancellations.store', ['order' => $order->public_id]);
    $payload = [
        'lines' => [['line_no' => 1, 'pack_qty' => 1]],
        'customer_notified_at' => now()->subHour()->toIso8601String(),
        'reason_detail' => 'Customer asked to reduce the quantity.',
        'reason_code' => 'below_break_override',
    ];

    $this->actingAs($staff)->withHeader('Idempotency-Key', 'staff-first')->post($url, $payload)->assertSessionHasNoErrors();
    expect(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'order.cancel_below_break')->where('subject_id', $order->id)->count())->toBe(1);
});

it('does not allow warehouse staff to record a money-affecting customer cancellation', function () {
    [$order, $line] = partialCase();
    $staff = User::factory()->withTwoFactor()->create();
    $role = Role::query()->where('code', 'warehouse')->first() ?? Role::factory()->create(['code' => 'warehouse']);
    RoleUser::create(['role_id' => $role->id, 'user_id' => $staff->id]);

    $this->actingAs($staff)->withHeader('Idempotency-Key', 'warehouse-attempt')
        ->post(route('staff.order-cancellations.store', ['order' => $order->public_id]), [
            'lines' => [['line_no' => 1, 'pack_qty' => 1]],
            'customer_notified_at' => now()->subHour()->toIso8601String(),
            'reason_detail' => 'Customer request',
        ])->assertForbidden();
    expect(DB::table('order_lines')->where('id', $line->id)->value('cancelled_base_qty'))->toBe(0);
});
