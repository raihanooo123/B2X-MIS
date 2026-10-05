<?php

use App\Domain\Billing\PaymentGateway;
use App\Domain\Credit\CreditLedger;
use App\Domain\Credit\CreditMovementType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeCardGateway;

/**
 * 05.2 §18.3 — the trade screens (read only, policy-gated, keyset paged)
 * and their /api/v1 actions (idempotent, re-authorised, company-scoped).
 * Requests are inserted directly: these tests are about the screens and
 * endpoints; TradeApprovalFlowTest covers how requests arise.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function tcpMember(Company $company, string $role, array $membership = []): User
{
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role] + $membership);

    return $user;
}

/** A buyer's on-account order awaiting a buyer-limit decision, with its £12 hold. */
function tcpPending(Company $company, User $buyer, array $request = []): OrderApprovalRequest
{
    $order = Order::factory()->create([
        'company_id' => $company->id, 'user_id' => $buyer->id, 'status' => 'awaiting_approval', 'confirmed_at' => null,
        'payment_method' => 'on_account', 'payment_status' => 'on_account', 'order_number' => 'SO-'.Str::upper(Str::random(6)),
        'subtotal_net_minor' => 1000, 'tax_minor' => 200, 'total_gross_minor' => 1200, 'placed_at' => now(),
    ]);
    CreditHold::factory()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => 1200, 'status' => 'held']);
    DB::table('companies')->where('id', $company->id)->increment('credit_held_minor', 1200);

    return OrderApprovalRequest::query()->create([
        'company_id' => $company->id, 'order_id' => $order->id, 'requested_by_user_id' => $buyer->id, 'approval_kind' => 'buyer_limit',
        'status' => 'pending', 'order_gross_minor' => 1200, 'requested_at' => now(), 'expires_at' => now()->addHours(48),
    ] + $request);
}

function tcpStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

it('shows the approval queue to owners and approvers only, with the shell navigation', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 100_000]);
    $buyer = tcpMember($company, 'buyer');
    $approver = tcpMember($company, 'approver');
    $request = tcpPending($company, $buyer);

    $this->actingAs($approver)->get('/trade/approvals')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Approvals/Index', false)
        ->where('filters.status', 'pending')
        ->where('filters.sort', 'requested_desc')
        ->where('pending_count', 1)
        ->has('rows', 1)
        ->where('rows.0.id', $request->public_id)
        ->where('rows.0.gross_minor', 1200)
        ->where('rows.0.can_decide', true)
        ->where('next_cursor', null)
        ->where('auth.trade_navigation.approvals', true)
        ->where('auth.trade_navigation.pending_approvals', 1));

    foreach ([$buyer, tcpMember($company, 'viewer'), tcpStaff('accounts')] as $denied) {
        $this->actingAs($denied)->get('/trade/approvals')->assertForbidden();
    }
    $this->actingAs($buyer)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.trade_navigation.approvals', false));
});

it('pages the queue by keyset, 50 at a time, and refuses a cursor from another list or filter', function () {
    $company = Company::factory()->create();
    $buyer = tcpMember($company, 'buyer');
    $owner = tcpMember($company, 'owner');
    $start = CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC');
    foreach (range(0, 50) as $i) {
        tcpPending($company, $buyer, ['requested_at' => $start->subMinutes($i), 'expires_at' => $start->addHours(48)]);
    }

    $first = $this->actingAs($owner)->get('/trade/approvals')->assertOk();
    $cursor = $first->viewData('page')['props']['next_cursor'];
    expect($first->viewData('page')['props']['rows'])->toHaveCount(50)
        ->and($cursor)->toBeString();

    $this->actingAs($owner)->get('/trade/approvals?cursor='.urlencode($cursor))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Approvals/Index', false)
        ->has('rows', 1)
        ->where('next_cursor', null));

    // Bound to its filters: the same cursor under another sort is refused, not restarted.
    $this->actingAs($owner)->get('/trade/approvals?sort=gross_desc&cursor='.urlencode($cursor))->assertSessionHasErrors('cursor');
    $this->actingAs($owner)->get('/trade/approvals?cursor=not-a-cursor')->assertSessionHasErrors('cursor');
});

it('filters by order number and status', function () {
    $company = Company::factory()->create();
    $buyer = tcpMember($company, 'buyer');
    $owner = tcpMember($company, 'owner');
    $wanted = tcpPending($company, $buyer);
    tcpPending($company, $buyer);
    tcpPending($company, $buyer, ['status' => 'rejected', 'decided_at' => now(), 'decided_by_user_id' => $owner->id, 'decision_reason' => 'No']);

    $this->actingAs($owner)->get('/trade/approvals?q='.urlencode($wanted->order->order_number))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('rows', 1)->where('rows.0.id', $wanted->public_id));
    $this->actingAs($owner)->get('/trade/approvals?status=decided')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('rows', 1)->where('rows.0.status', 'rejected')->where('rows.0.can_decide', false));
});

it('shows a request in detail to its company only; the requester cannot decide their own', function () {
    $company = Company::factory()->create();
    $buyer = tcpMember($company, 'approver');
    $approver = tcpMember($company, 'approver');
    $request = tcpPending($company, $buyer);

    $this->actingAs($approver)->get("/trade/approvals/{$request->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Approvals/Show', false)
        ->where('approval.id', $request->public_id)
        ->where('approval.can_decide', true)
        ->where('approval.buyer.role', 'approver')
        ->where('approval.order.total_gross_minor', 1200));
    $this->actingAs($buyer)->get("/trade/approvals/{$request->public_id}")->assertInertia(fn (AssertableInertia $page) => $page->where('approval.can_decide', false));

    $outsider = tcpMember(Company::factory()->create(), 'owner');
    $this->actingAs($outsider)->get("/trade/approvals/{$request->public_id}")->assertNotFound();
});

it('approves through the API once per Idempotency-Key, and confirms the order', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 100_000]);
    $buyer = tcpMember($company, 'buyer');
    $approver = tcpMember($company, 'approver');
    $request = tcpPending($company, $buyer);
    $key = (string) Str::uuid();

    $this->actingAs($approver)->postJson("/api/v1/approvals/{$request->public_id}/approve", ['expected_status' => 'pending'])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');

    $first = $this->actingAs($approver)->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/approvals/{$request->public_id}/approve", ['expected_status' => 'pending'])
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.order.status', 'confirmed');
    $second = $this->actingAs($approver)->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/approvals/{$request->public_id}/approve", ['expected_status' => 'pending'])->assertOk();

    expect($second->json())->toBe($first->json())
        ->and(DB::table('audit_log')->where('action', 'credit.operation')->where('subject_type', 'order_approval_request')->count())->toBe(1);
});

it('refuses through the API: a reject with no reason, a buyer deciding, another company, a decided request', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 100_000]);
    $buyer = tcpMember($company, 'buyer');
    $approver = tcpMember($company, 'approver');
    $request = tcpPending($company, $buyer);
    $post = fn (User $as, string $action, array $body = []) => $this->actingAs($as)->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/approvals/{$request->public_id}/{$action}", ['expected_status' => 'pending'] + $body);

    $post($approver, 'reject')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.0.field', 'reason');
    $post(tcpMember($company, 'buyer'), 'approve')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $post(tcpMember(Company::factory()->create(), 'owner'), 'approve')->assertNotFound();

    $post($approver, 'reject', ['reason' => 'Not this month'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $post(tcpMember($company, 'owner'), 'approve')->assertStatus(409)->assertJsonPath('error.code', 'approval_not_pending');
    expect($request->order->fresh()->status)->toBe('cancelled');
});

it('bulk-rejects selected requests one by one, reporting each result', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 100_000]);
    $buyer = tcpMember($company, 'buyer');
    $owner = tcpMember($company, 'owner');
    $open = tcpPending($company, $buyer);
    $decided = tcpPending($company, $buyer, ['status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $owner->id]);
    $foreign = tcpPending(Company::factory()->create(), $buyer);

    $response = $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/approvals/reject', ['ids' => [$open->public_id, $decided->public_id, $foreign->public_id], 'reason' => 'Budget frozen'])
        ->assertOk();

    expect(collect($response->json('data'))->mapWithKeys(fn ($r) => [$r['id'] => [$r['ok'], $r['code']]])->all())->toBe([
        $open->public_id => [true, null],
        $decided->public_id => [false, 'approval_not_pending'],
        $foreign->public_id => [false, 'not_found'],
    ])
        ->and($open->fresh()->status)->toBe('rejected')
        ->and($foreign->fresh()->status)->toBe('pending');
});

it('lets an owner set a member’s role, limit in pounds and approval flag through the API', function () {
    $company = Company::factory()->create();
    $owner = tcpMember($company, 'owner');
    $buyer = tcpMember($company, 'buyer');

    $this->actingAs($owner)->get('/trade/account/users')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Account/Users', false)->has('members', 2));

    $this->actingAs($owner)->patchJson("/api/v1/company-users/{$buyer->public_id}", ['role' => 'buyer', 'order_limit' => '2500.50', 'requires_approval' => true])
        ->assertOk()->assertJsonPath('data.order_limit', '2500.50');
    expect(CompanyUser::query()->where('user_id', $buyer->id)->value('order_limit_minor'))->toBe(250050);

    $this->actingAs($owner)->patchJson("/api/v1/company-users/{$owner->public_id}", ['role' => 'buyer', 'order_limit' => null, 'requires_approval' => false])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'role');
    $this->actingAs($buyer)->patchJson("/api/v1/company-users/{$owner->public_id}", ['role' => 'viewer', 'order_limit' => null, 'requires_approval' => false])->assertForbidden();
    $this->actingAs($buyer)->get('/trade/account/users')->assertForbidden();
});

it('shows owners and approvers the credit summary, ageing and outstanding invoices — over-limit as zero plus the excess', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 1000, 'credit_used_minor' => 1500, 'credit_held_minor' => 0]);
    $owner = tcpMember($company, 'owner');
    Invoice::factory()->create(['company_id' => $company->id, 'status' => 'overdue', 'total_gross_minor' => 1500, 'paid_minor' => 0, 'due_at' => now()->subDays(40)]);
    Invoice::factory()->create(['company_id' => $company->id, 'status' => 'paid', 'total_gross_minor' => 900, 'paid_minor' => 900, 'due_at' => now()->subDays(40)]);

    $this->actingAs($owner)->get('/trade/account/credit')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Account/Credit', false)
        ->where('summary.available_minor', 0)
        ->where('summary.over_limit_minor', 500)
        ->where('summary.on_account.allowed', false)
        ->where('summary.on_account.code', 'overdue_debt')
        ->where('summary.overdue.count', 1)
        ->where('summary.overdue.amount_minor', 1500)
        ->where('summary.ageing.2.bucket', 'days_31_60')
        ->where('summary.ageing.2.amount_minor', 1500)
        ->has('rows', 1)
        ->where('rows.0.outstanding_minor', 1500)
        ->where('rows.0.days_overdue', 40));

    $this->actingAs(tcpMember($company, 'buyer'))->get('/trade/account/credit')->assertForbidden();
    $this->actingAs(tcpMember($company, 'viewer'))->get('/trade/account/balance')->assertForbidden();
});

it('shows the balance ledger newest first', function () {
    $company = Company::factory()->create();
    $approver = tcpMember($company, 'approver');
    DB::transaction(fn () => (new CreditLedger)->append($company, 'adjust:1', [['type' => CreditMovementType::Adjustment, 'amount' => 2000, 'reason' => 'opening']]));
    $this->travel(1)->minutes();
    DB::transaction(fn () => (new CreditLedger)->append($company->fresh(), 'adjust:2', [['type' => CreditMovementType::Adjustment, 'amount' => -500, 'reason' => 'correction']]));

    $this->actingAs($approver)->get('/trade/account/balance')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Account/Balance', false)
        ->where('balance_minor', 1500)
        ->has('rows', 2)
        ->where('rows.0.amount_minor', -500)
        ->where('rows.0.balance_after_minor', 1500)
        ->where('rows.1.amount_minor', 2000));
});

it('takes card payment for an approved order: intent, authorisation, confirmation and capture', function () {
    $gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $gateway);
    config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.key' => 'pk_test_fake']);
    $company = Company::factory()->create();
    $buyer = tcpMember($company, 'buyer');
    $approver = tcpMember($company, 'approver');
    $request = tcpPending($company, $buyer, ['status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $approver->id]);
    $order = $request->order;
    DB::table('orders')->where('id', $order->id)->update(['status' => 'pending_payment', 'payment_method' => 'card', 'payment_status' => 'unpaid']);

    $this->actingAs($buyer)->get("/trade/orders/{$order->public_id}/pay")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Orders/Pay', false)->where('refusal', null)->where('stripe_key', 'pk_test_fake'));
    $this->actingAs($approver)->get("/trade/orders/{$order->public_id}/pay")->assertNotFound();

    $intent = $this->actingAs($buyer)->postJson("/api/v1/orders/{$order->public_id}/card-intent")->assertOk()->assertJsonPath('data.amount_minor', 1200)->json('data.id');
    $gateway->authorise($intent);

    $this->actingAs($buyer)->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/orders/{$order->public_id}/pay", ['payment_intent_id' => $intent])
        ->assertOk()->assertJsonPath('data.status', 'confirmed');

    expect($order->fresh()->status)->toBe('confirmed')
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and($gateway->captured)->toBe([$intent]);

    $this->actingAs($buyer)->postJson("/api/v1/orders/{$order->public_id}/card-intent")->assertStatus(409)->assertJsonPath('error.code', 'order_not_payable');
});
