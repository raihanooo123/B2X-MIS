<?php

use App\Domain\Billing\PaymentGateway;
use App\Domain\Credit\CreditControl;
use App\Domain\Credit\CreditLedger;
use App\Domain\Credit\CreditMovementType;
use App\Domain\Credit\CreditPayouts;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\CreditSettings;
use App\Filament\Pages\CreditExceptionsPage;
use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeCardGateway;

/**
 * 05.2 §9, §18.3, §18.5 — accounts' credit control (limit, terms,
 * suspension with reason, reinstatement), the nightly debt suspension,
 * and balance payouts with second-person approval.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->gateway = new FakeCardGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);
});

function cctStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

function cctRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (CreditRefused $e) {
        return $e->reason;
    }

    return null;
}

/** A company with £50 of spendable balance and the card payment it can go back to. */
function cctBalance(int $balance = 5000, int $card = 8000): array
{
    $company = Company::factory()->create(['payment_terms' => 'net30']);
    DB::transaction(fn () => (new CreditLedger)->append($company, "opening:{$company->id}", [['type' => CreditMovementType::Adjustment, 'amount' => $balance, 'reason' => 'opening']]));
    $payment = Payment::factory()->create(['company_id' => $company->id, 'gateway' => 'stripe', 'status' => 'captured', 'amount_minor' => $card]);

    return [$company->fresh(), $payment];
}

it('lets accounts change the limit, terms and status with a reason, audited with the actor', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 100_000]);
    $accounts = cctStaff('accounts');

    (new CreditControl)->update($company->id, $accounts, 250_000, 'net60', 'suspended', 'Payment plan agreed', 'debt');

    $fresh = $company->fresh();
    expect($fresh->credit_limit_minor)->toBe(250_000)
        ->and($fresh->payment_terms)->toBe('net60')
        ->and($fresh->status)->toBe('suspended')
        ->and((new CreditSettings)->suspensionReason($company->id))->toBe('debt')
        ->and(DB::table('audit_log')->where('action', 'credit_limit.changed')->where('actor_user_id', $accounts->id)->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'credit.operation')->where('subject_type', 'company')->where('reason', 'debt: Payment plan agreed')->count())->toBe(1);

    (new CreditControl)->update($company->id, $accounts, 250_000, 'net60', 'approved', 'Paid in full');
    expect($company->fresh()->status)->toBe('approved');
});

it('needs a reason, and a suspension reason when suspending', function () {
    $company = Company::factory()->create();
    $accounts = cctStaff('accounts');

    expect(fn () => (new CreditControl)->update($company->id, $accounts, 1000, 'net30', 'approved', ' '))->toThrow(ValidationException::class)
        ->and(fn () => (new CreditControl)->update($company->id, $accounts, 1000, 'net30', 'suspended', 'Late'))->toThrow(ValidationException::class)
        ->and(fn () => (new CreditControl)->update($company->id, $accounts, -1, 'net30', 'approved', 'x'))->toThrow(ValidationException::class);
});

it('lets only accounts and admin control credit — not owners, approvers or other staff', function (string $who) {
    $company = Company::factory()->create();
    $actor = $who === 'warehouse' ? cctStaff('warehouse') : User::factory()->create();
    if ($who !== 'warehouse') {
        CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'role' => $who]);
    }

    expect(fn () => (new CreditControl)->update($company->id, $actor, 9_999_999, 'net60', 'approved', 'Please'))->toThrow(AuthorizationException::class);
    expect($company->fresh()->credit_limit_minor)->not->toBe(9_999_999);
})->with(['owner', 'approver', 'warehouse']);

it('suspends for debt overnight once an invoice is 30 days overdue, not a day before, and never reinstates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 02:15:00', 'UTC'));
    $company = Company::factory()->create(['payment_terms' => 'net30']);
    $other = Company::factory()->create(['payment_terms' => 'net30', 'status' => 'closed']);
    Invoice::factory()->create(['company_id' => $company->id, 'status' => 'overdue', 'total_gross_minor' => 1000, 'paid_minor' => 0, 'due_at' => now()->subDays(29)]);
    Invoice::factory()->create(['company_id' => $other->id, 'status' => 'overdue', 'total_gross_minor' => 1000, 'paid_minor' => 0, 'due_at' => now()->subDays(90)]);

    expect((new CreditControl)->suspendOverdue())->toBe(0);

    $this->travel(1)->days();
    expect((new CreditControl)->suspendOverdue())->toBe(1)
        ->and((new CreditControl)->suspendOverdue())->toBe(0)
        ->and($company->fresh()->status)->toBe('suspended')
        ->and((new CreditSettings)->suspensionReason($company->id))->toBe('debt')
        ->and($other->fresh()->status)->toBe('closed')
        ->and(DB::table('audit_log')->where('action', 'credit.operation')->where('actor_type', 'system')->where('company_id', $company->id)->count())->toBe(1);
});

it('reserves a payout at once, needs a second person to approve, then refunds the original card', function () {
    [$company, $payment] = cctBalance();
    $requester = cctStaff('accounts');
    $approver = cctStaff('admin');
    $payouts = new CreditPayouts;

    $payout = $payouts->request($company->id, $requester, 'original_card', 3000, $payment->public_id, 'Customer closing account');

    expect($company->fresh()->account_balance_minor)->toBe(2000)
        ->and($payout->status)->toBe('pending')
        ->and(cctRefusal(fn () => $payouts->approve($payout->id, $requester)))->toBe('second_approver_required');

    $payouts->approve($payout->id, $approver);
    $payouts->settle($payout->id);

    expect($payout->fresh()->status)->toBe('paid')
        ->and($payout->fresh()->approved_by_user_id)->toBe($approver->id)
        ->and($this->gateway->refunds)->toHaveCount(1)
        ->and($this->gateway->refunds[0]['amount_minor'])->toBe(3000)
        // Paid records the result without a second debit.
        ->and($company->fresh()->account_balance_minor)->toBe(2000)
        ->and(DB::table('account_credit_movements')->where('company_id', $company->id)->where('movement_type', 'payout_reserved')->count())->toBe(1);
});

it('returns a refused payout to the balance with one reversal', function () {
    [$company, $payment] = cctBalance();
    $payouts = new CreditPayouts;
    $payout = $payouts->request($company->id, cctStaff('accounts'), 'original_card', 3000, $payment->public_id, 'Refund');
    $this->gateway->failRefund = true;

    $payouts->approve($payout->id, cctStaff('admin'));
    $payouts->settle($payout->id);
    $payouts->settle($payout->id);

    expect($payout->fresh()->status)->toBe('failed')
        ->and($company->fresh()->account_balance_minor)->toBe(5000)
        ->and(DB::table('account_credit_movements')->where('company_id', $company->id)->where('movement_type', 'reversal')->count())->toBe(1);
});

it('caps a payout at the balance and at what can still go back to that card, and refuses bank payouts for now', function () {
    [$company, $payment] = cctBalance(balance: 5000, card: 4000);
    $accounts = cctStaff('accounts');
    $payouts = new CreditPayouts;

    expect(cctRefusal(fn () => $payouts->request($company->id, $accounts, 'original_card', 4001, $payment->public_id, 'x')))->toBe('payout_exceeds_card')
        ->and(cctRefusal(fn () => $payouts->request($company->id, $accounts, 'bank', 100, null, 'x')))->toBe('bank_payout_unavailable');

    $payouts->request($company->id, $accounts, 'original_card', 4000, $payment->public_id, 'All of it');
    expect(cctRefusal(fn () => $payouts->request($company->id, $accounts, 'original_card', 1, $payment->public_id, 'More')))->toBe('payout_exceeds_card')
        // Another company's payment is not a source.
        ->and(cctRefusal(fn () => $payouts->request($company->id, $accounts, 'original_card', 100, Payment::factory()->create()->public_id, 'x')))->toBe('source_payment_invalid');
});

it('releases a rejected pending payout back to the balance', function () {
    [$company, $payment] = cctBalance();
    $payouts = new CreditPayouts;
    $payout = $payouts->request($company->id, cctStaff('accounts'), 'original_card', 1000, $payment->public_id, 'x');

    $payouts->reject($payout->id, cctStaff('admin'), 'Customer changed their mind');

    expect($payout->fresh()->status)->toBe('failed')
        ->and($company->fresh()->account_balance_minor)->toBe(5000)
        ->and(cctRefusal(fn () => $payouts->reject($payout->id, cctStaff('admin'), 'again')))->toBe('payout_not_pending');
});

it('shows the credit-control screens to accounts and admin only', function () {
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);

    $this->actingAs(cctStaff('accounts'))->get(CreditExceptionsPage::getUrl())->assertOk()->assertSee('Orders above available credit');
    $this->actingAs(cctStaff('accounts'))->get(CompanyResource::getUrl('credit', ['record' => $company]))->assertOk()->assertSee('Limit, terms and status');
    $this->actingAs(cctStaff('warehouse'))->get(CreditExceptionsPage::getUrl())->assertForbidden();
    $this->actingAs(cctStaff('warehouse'))->get(CompanyResource::getUrl('credit', ['record' => $company]))->assertForbidden();
    $this->actingAs($owner)->get(CompanyResource::getUrl('credit', ['record' => $company]))->assertForbidden();
});
