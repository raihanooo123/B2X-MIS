<?php

use App\Domain\Billing\CardIntent;
use App\Domain\Credit\ApprovedOrderPayment;
use App\Domain\Credit\CreditExpiry;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\CreditSettings;
use App\Domain\Credit\TradeApprovals;
use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutRequest;
use App\Domain\Ordering\CheckoutService;
use App\Domain\Pricing\OrderLineRequest;
use App\Domain\Pricing\OrderPricingPipeline;
use App\Models\Cart;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\SystemConfiguration;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §18.1–§18.2 (CR1–CR5 extended): the trade checkout gates, buyer
 * and credit approvals, the coordinated reaper and paying after approval.
 * One SKU at £1.00 net, 20% VAT: 10 packs of 1 = £12.00 gross (1200).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    NumberSequence::factory()->forSeries('order_number', 'SO-')->create();
    $this->location = Location::factory()->default()->create();
    $taxClass = TaxClass::factory()->create();
    // Pinned windows: some tests move the clock (05.16 test rules).
    TaxRate::factory()->for($taxClass)->forPeriod(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'))
        ->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $this->sku = Sku::factory()->create(['is_stock_tracked' => true, 'tracking_mode' => 'none', 'tax_class_id' => $taxClass->id]);
    $this->pack = Pack::factory()->for($this->sku)->create(['base_units' => 1]);
    $list = PriceList::factory()->create(['scope' => 'base', 'validity' => '[2026-01-01 00:00:00+00,)']);
    PriceListItem::factory()->for($list, 'priceList')->for($this->sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 10000]);
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 100, 'allocated_base_qty' => 0]);
});

/** A trade company on 30-day terms with £10,000 credit, and its first buyer. */
function tafCompany(array $company = [], array $membership = []): array
{
    $record = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 1_000_000] + $company);

    return [$record, tafMember($record, $membership + ['role' => 'buyer'])];
}

function tafMember(Company $company, array $membership = ['role' => 'buyer']): User
{
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id] + $membership);

    return $user;
}

function tafStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

function tafCheckout(Company $company, User $buyer, int $packs = 10, string $method = 'on_account', ?CardIntent $card = null): Order
{
    $cart = Cart::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id]);
    (new CartService)->addLine($cart, test()->pack, $packs);
    $total = (new OrderPricingPipeline)->price([new OrderLineRequest(skuId: test()->sku->id, baseQty: $packs)], companyId: $company->id, tierId: null, deliveryCountryCode: 'GB')->totalGrossMinor;

    return (new CheckoutService)->checkout(new CheckoutRequest(
        cartId: $cart->id, companyId: $company->id, userId: $buyer->id, paymentMethod: $method,
        expectedTotalGrossMinor: $total, deliveryCountryCode: 'GB', cardAuthorisation: $card,
    ));
}

function tafAllocated(): int
{
    return (int) StockLevel::identity(test()->sku->id, test()->location->id, null)->value('allocated_base_qty');
}

function tafRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (CreditRefused $e) {
        return $e->reason;
    }

    return null;
}

it('places an on-account order within the buyer limit straight away, holding its credit', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 1200]);

    $order = tafCheckout($company, $buyer);

    expect($order->status)->toBe('confirmed')
        ->and(OrderApprovalRequest::query()->count())->toBe(0)
        ->and(tafAllocated())->toBe(10)
        ->and($company->fresh()->credit_held_minor)->toBe(1200);
});

it('sends an order above the buyer limit for approval, holding stock and credit for 48 hours from submission', function (int $limit, bool $needsApproval) {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => $limit]);
    $approver = tafMember($company, ['role' => 'approver']);

    $order = tafCheckout($company, $buyer);

    if (! $needsApproval) {
        expect($order->status)->toBe('confirmed');

        return;
    }
    $request = OrderApprovalRequest::query()->where('order_id', $order->id)->sole();
    expect($order->status)->toBe('awaiting_approval')
        ->and($order->confirmed_at)->toBeNull()
        ->and($request->approval_kind)->toBe('buyer_limit')
        ->and($request->order_gross_minor)->toBe(1200)
        ->and($request->expires_at->equalTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC')))->toBeTrue()
        // Funded: the 48 hours hold stock and credit (05.2 §18.1).
        ->and(tafAllocated())->toBe(10)
        ->and($company->fresh()->credit_held_minor)->toBe(1200)
        ->and(DB::table('notification_log')->where('notification_key', 'order.awaiting_approval')->where('recipient', $approver->email)->count())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'order.awaiting_approval')->where('recipient', $buyer->email)->count())->toBe(0);
})->with([
    'a £0 limit' => [0, true],
    'a penny under the gross' => [1199, true],
    'exactly the gross' => [1200, false],
]);

it('sends every order of a buyer who always needs approval', function () {
    [$company, $buyer] = tafCompany(membership: ['requires_approval' => true]);

    expect(tafCheckout($company, $buyer, 1)->status)->toBe('awaiting_approval');
});

it('refuses a viewer, a member removed or suspended, and someone outside the company', function (string $case) {
    [$company, $buyer] = tafCompany();
    match ($case) {
        'viewer' => CompanyUser::query()->where('user_id', $buyer->id)->update(['role' => 'viewer']),
        'suspended' => DB::table('users')->where('id', $buyer->id)->update(['status' => 'suspended']),
        'removed' => CompanyUser::query()->where('user_id', $buyer->id)->delete(),
        'outsider' => $buyer = User::factory()->create(),
    };

    expect(tafRefusal(fn () => tafCheckout($company, $buyer)))->toBe('not_permitted_to_order')
        ->and(Order::query()->count())->toBe(0);
})->with(['viewer', 'suspended', 'removed', 'outsider']);

it('blocks on account from the due instant of an unpaid invoice (grace 0), not a second before', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    [$company, $buyer] = tafCompany();
    Invoice::factory()->create(['company_id' => $company->id, 'status' => 'issued', 'total_gross_minor' => 5000, 'paid_minor' => 0, 'due_at' => now()->addSecond()]);

    expect(tafCheckout($company, $buyer, 1)->status)->toBe('confirmed');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:02', 'UTC'));
    expect(tafRefusal(fn () => tafCheckout($company, $buyer, 1)))->toBe('overdue_debt');
    // Card and bank transfer stay open.
    expect(tafCheckout($company, $buyer, 1, 'bacs')->status)->toBe('confirmed');

    // A configured grace moves the boundary.
    SystemConfiguration::query()->create(['config_key' => CreditSettings::GRACE_DAYS, 'scope' => 'company', 'company_id' => $company->id, 'value_type' => 'int', 'value_int' => 3]);
    expect(tafCheckout($company, $buyer, 1)->status)->toBe('confirmed');
});

it('blocks on account while suspended, and allows prepayment only for an ordinary debt suspension', function () {
    [$company, $buyer] = tafCompany(['status' => 'suspended']);

    expect(tafRefusal(fn () => tafCheckout($company, $buyer, 1)))->toBe('credit_account_suspended')
        // No recorded reason fails closed: no sales at all.
        ->and(tafRefusal(fn () => tafCheckout($company, $buyer, 1, 'bacs')))->toBe('credit_account_suspended');

    (new CreditSettings)->recordSuspensionReason($company->id, 'debt', null);
    expect(tafCheckout($company, $buyer, 1, 'bacs')->status)->toBe('confirmed')
        ->and(tafRefusal(fn () => tafCheckout($company, $buyer, 1)))->toBe('credit_account_suspended');

    (new CreditSettings)->recordSuspensionReason($company->id, 'fraud', null);
    expect(tafRefusal(fn () => tafCheckout($company, $buyer, 1, 'bacs')))->toBe('credit_account_suspended');
});

it('refuses on account for a company on prepayment terms', function () {
    [$company, $buyer] = tafCompany(['payment_terms' => 'prepay']);

    expect(tafRefusal(fn () => tafCheckout($company, $buyer, 1)))->toBe('payment_method_not_available');
});

it('places a card order needing approval with no card, and refuses one with a card authorisation', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $card = new CardIntent('pi_test1234567890', null, 'requires_capture', 1200, 'GBP');

    expect(tafRefusal(fn () => tafCheckout($company, $buyer, 10, 'card', $card)))->toBe('approval_required');

    $order = tafCheckout($company, $buyer, 10, 'card');
    expect($order->status)->toBe('awaiting_approval')
        ->and(Payment::query()->count())->toBe(0)
        ->and(tafAllocated())->toBe(10)
        // A company paying by card takes no credit hold (05.2 §8.1 row 2).
        ->and($company->fresh()->credit_held_minor)->toBe(0);

    // Without approval, a card order still needs its authorisation.
    CompanyUser::query()->where('user_id', $buyer->id)->update(['order_limit_minor' => null]);
    expect(tafRefusal(fn () => tafCheckout($company, $buyer, 1, 'card')))->toBe('card_authorisation_required');
});

it('lets another approver approve: the on-account order is confirmed, audited and the buyer told', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $order = tafCheckout($company, $buyer);
    $request = OrderApprovalRequest::query()->where('order_id', $order->id)->sole();

    $decided = (new TradeApprovals)->decide($request->id, $approver, true);

    expect($decided->status)->toBe('approved')
        ->and($decided->decided_by_user_id)->toBe($approver->id)
        ->and($order->fresh()->status)->toBe('confirmed')
        ->and($order->fresh()->confirmed_at)->not->toBeNull()
        ->and($company->fresh()->credit_held_minor)->toBe(1200)
        ->and(DB::table('audit_log')->where('action', 'credit.operation')->where('subject_type', 'order_approval_request')->where('subject_id', $request->id)->where('actor_user_id', $approver->id)->count())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'order.approval_granted')->where('recipient', $buyer->email)->count())->toBe(1);
});

it('allows no self-approval, and no buyer, viewer, other company or staff to decide a buyer-limit request', function (string $who) {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $order = tafCheckout($company, $buyer);
    $request = OrderApprovalRequest::query()->where('order_id', $order->id)->sole();

    $actor = match ($who) {
        'self' => tap($buyer, fn () => CompanyUser::query()->where('user_id', $buyer->id)->update(['role' => 'approver'])),
        'buyer' => tafMember($company, ['role' => 'buyer']),
        'viewer' => tafMember($company, ['role' => 'viewer']),
        'other company approver' => tafMember(Company::factory()->create(), ['role' => 'approver']),
        'accounts' => tafStaff('accounts'),
    };

    expect(fn () => (new TradeApprovals)->decide($request->id, $actor, true))->toThrow(AuthorizationException::class);
    expect($request->fresh()->status)->toBe('pending')
        ->and($order->fresh()->status)->toBe('awaiting_approval');
})->with(['self', 'buyer', 'viewer', 'other company approver', 'accounts']);

it('rejects with a reason: the order is cancelled, stock and credit released, the buyer told', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $owner = tafMember($company, ['role' => 'owner']);
    $order = tafCheckout($company, $buyer);
    $request = OrderApprovalRequest::query()->where('order_id', $order->id)->sole();

    expect(fn () => (new TradeApprovals)->decide($request->id, $owner, false))->toThrow(ValidationException::class);

    (new TradeApprovals)->decide($request->id, $owner, false, 'Over this month’s budget');

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($request->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->decision_reason)->toBe('Over this month’s budget')
        ->and(tafAllocated())->toBe(0)
        ->and(StockAllocation::query()->where('status', 'allocated')->count())->toBe(0)
        ->and($company->fresh()->credit_held_minor)->toBe(0)
        ->and(CreditHold::query()->where('order_id', $order->id)->value('status'))->toBe('released')
        ->and(DB::table('order_cancellations')->where('order_id', $order->id)->where('reason_code', 'approval_rejected')->where('credit_hold_reduction_minor', 1200)->count())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', 'order.approval_rejected')->where('recipient', $buyer->email)->count())->toBe(1);
});

it('lets expiry win at the exact expiry instant', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $request = OrderApprovalRequest::query()->where('order_id', tafCheckout($company, $buyer)->id)->sole();

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
    $this->flushSession();

    expect(tafRefusal(fn () => (new TradeApprovals)->decide($request->id, $approver, true)))->toBe('approval_expired')
        ->and($request->fresh()->status)->toBe('pending');
});

it('rechecks the buyer at decision time: a buyer removed since cannot have the order approved', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $request = OrderApprovalRequest::query()->where('order_id', tafCheckout($company, $buyer)->id)->sole();
    CompanyUser::query()->where('user_id', $buyer->id)->delete();

    expect(tafRefusal(fn () => (new TradeApprovals)->decide($request->id, $approver, true)))->toBe('not_permitted_to_order')
        ->and($request->fresh()->status)->toBe('pending');
});

it('repeats the same decision by the same person as a no-op, and refuses a different one', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $other = tafMember($company, ['role' => 'owner']);
    $request = OrderApprovalRequest::query()->where('order_id', tafCheckout($company, $buyer)->id)->sole();

    (new TradeApprovals)->decide($request->id, $approver, true);
    $again = (new TradeApprovals)->decide($request->id, $approver, true);

    expect($again->status)->toBe('approved')
        ->and(DB::table('audit_log')->where('action', 'credit.operation')->where('subject_type', 'order_approval_request')->count())->toBe(1)
        ->and(tafRefusal(fn () => (new TradeApprovals)->decide($request->id, $other, false, 'No')))->toBe('approval_not_pending');
});

it('moves an approved card order to pending payment, confirms it once paid, and never expires a paid order', function () {
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $order = tafCheckout($company, $buyer, 10, 'card');
    (new TradeApprovals)->decide(OrderApprovalRequest::query()->where('order_id', $order->id)->value('id'), $approver, true);

    expect($order->fresh()->status)->toBe('pending_payment');

    $payments = new ApprovedOrderPayment;
    expect(tafRefusal(fn () => $payments->confirm($order->id, $buyer, new CardIntent('pi_wrongamount123', null, 'requires_capture', 999, 'GBP'))))->toBe('payment_amount_mismatch')
        ->and(tafRefusal(fn () => $payments->confirm($order->id, $approver, new CardIntent('pi_otherbuyer1234', null, 'requires_capture', 1200, 'GBP'))))->toBe('not_found');

    $confirmed = $payments->confirm($order->id, $buyer, new CardIntent('pi_rightamount123', null, 'requires_capture', 1200, 'GBP', [], 'visa', '4242'));

    expect($confirmed->status)->toBe('confirmed')
        ->and(Payment::query()->where('order_id', $order->id)->value('status'))->toBe('authorized');

    $this->travel(3)->hours();
    expect((new CreditExpiry)->sweep())->toBe(0)
        ->and($order->fresh()->status)->toBe('confirmed');
});

it('cancels an approved card order not paid within 2 hours of approval', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $order = tafCheckout($company, $buyer, 10, 'card');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    (new TradeApprovals)->decide(OrderApprovalRequest::query()->where('order_id', $order->id)->value('id'), $approver, true);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:59:59', 'UTC'));
    expect((new CreditExpiry)->sweep())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    expect((new CreditExpiry)->sweep())->toBe(1)
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and(tafAllocated())->toBe(0)
        ->and(DB::table('order_cancellations')->where('order_id', $order->id)->value('reason_code'))->toBe('payment_expired');
});

it('expires a request at 48 hours, releasing stock and credit once, and does nothing on a second run', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $order = tafCheckout($company, $buyer);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:59:59', 'UTC'));
    expect((new CreditExpiry)->sweep())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
    $this->flushSession();
    expect((new CreditExpiry)->sweep())->toBe(1)
        ->and((new CreditExpiry)->sweep())->toBe(0)
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and(OrderApprovalRequest::query()->where('order_id', $order->id)->value('status'))->toBe('expired')
        ->and(tafAllocated())->toBe(0)
        ->and($company->fresh()->credit_held_minor)->toBe(0)
        ->and(DB::table('order_cancellations')->where('order_id', $order->id)->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'credit.operation')->where('actor_type', 'system')->where('subject_type', 'order')->count())->toBe(1);
});

it('never expires an order that has been invoiced', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    [$company, $buyer] = tafCompany(membership: ['order_limit_minor' => 100]);
    $order = tafCheckout($company, $buyer);
    Invoice::factory()->create(['company_id' => $company->id, 'order_id' => $order->id, 'status' => 'issued']);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    $this->flushSession();

    expect((new CreditExpiry)->sweep())->toBe(0)
        ->and($order->fresh()->status)->toBe('awaiting_approval');
});

it('funds a credit shortfall only once the limit covers it: then stock and credit are reserved', function () {
    [$company, $buyer] = tafCompany(['credit_limit_minor' => 100]);
    $accounts = tafStaff('accounts');
    $order = tafCheckout($company, $buyer);
    $request = OrderApprovalRequest::query()->where('order_id', $order->id)->sole();

    expect($request->approval_kind)->toBe('credit_exception')
        ->and(tafAllocated())->toBe(0)
        ->and(DB::table('notification_log')->where('notification_key', 'order.awaiting_approval')->where('recipient', $accounts->email)->count())->toBe(1)
        ->and(tafRefusal(fn () => (new TradeApprovals)->decide($request->id, $accounts, true)))->toBe('insufficient_credit')
        ->and(tafAllocated())->toBe(0);

    DB::table('companies')->where('id', $company->id)->update(['credit_limit_minor' => 1_000_000]);
    (new TradeApprovals)->decide($request->id, $accounts, true);

    expect($order->fresh()->status)->toBe('confirmed')
        ->and(tafAllocated())->toBe(10)
        ->and($company->fresh()->credit_held_minor)->toBe(1200)
        ->and(CreditHold::query()->where('order_id', $order->id)->value('status'))->toBe('held');
});

it('keeps the decisions apart: company approvers cannot decide a shortfall, nor accounts a buyer limit', function () {
    [$company, $buyer] = tafCompany(['credit_limit_minor' => 100], ['order_limit_minor' => 100]);
    $approver = tafMember($company, ['role' => 'approver']);
    $accounts = tafStaff('accounts');
    $order = tafCheckout($company, $buyer);
    $requests = OrderApprovalRequest::query()->where('order_id', $order->id)->get()->keyBy('approval_kind');

    expect(fn () => (new TradeApprovals)->decide($requests['credit_exception']->id, $approver, true))->toThrow(AuthorizationException::class);
    expect(fn () => (new TradeApprovals)->decide($requests['buyer_limit']->id, $accounts, true))->toThrow(AuthorizationException::class);

    // The buyer approval alone does not confirm an unfunded order.
    (new TradeApprovals)->decide($requests['buyer_limit']->id, $approver, true);
    expect($order->fresh()->status)->toBe('awaiting_approval')
        ->and(tafAllocated())->toBe(0);
});

it('lets the buyer pay a shortfall in advance instead: stock reserved now, then payment', function () {
    [$company, $buyer] = tafCompany(['credit_limit_minor' => 100]);
    $order = tafCheckout($company, $buyer);

    $updated = (new TradeApprovals)->payInAdvance($order->id, $buyer);

    expect($updated->status)->toBe('pending_payment')
        ->and($updated->payment_method)->toBe('card')
        ->and(tafAllocated())->toBe(10)
        ->and($company->fresh()->credit_held_minor)->toBe(0)
        ->and(OrderApprovalRequest::query()->where('order_id', $order->id)->value('decision_reason'))->toBe('buyer_paid_in_advance')
        ->and(tafRefusal(fn () => (new TradeApprovals)->payInAdvance($order->id, $buyer)))->toBe('approval_not_pending');
});
