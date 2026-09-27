<?php

use App\Domain\Accounts\ApplicationReviewService;
use App\Domain\Accounts\ApprovalTerms;
use App\Domain\Accounts\RejectionCategory;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\PaymentTerms;
use App\Domain\Identity\CompanyMemberships;
use App\Domain\Identity\StaffSuspensionService;
use App\Domain\Notifications\Notices\ApplicationApproved;
use App\Domain\Notifications\Notices\ApplicationRejected;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Pricing\PricingCache;
use App\Filament\Resources\TradeApplicationResource\Pages\ListTradeApplications;
use App\Filament\Resources\TradeApplicationResource\Pages\ViewTradeApplication;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\DeliveryZone;
use App\Models\NumberSequence;
use App\Models\PriceTier;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
    $this->bronze = PriceTier::factory()->default()->create(['code' => 'bronze', 'name' => 'Bronze', 'position' => 1]);
    $this->gold = PriceTier::factory()->create(['code' => 'gold', 'name' => 'Gold', 'position' => 3]);

    $this->forgotten = [];
    Event::listen(ForgettingKey::class, fn (ForgettingKey $event) => $this->forgotten[] = $event->key);
});

function reviewStaff(string $role = 'admin', string $status = 'active'): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);
    RoleUser::create(['role_id' => Role::query()->where('code', $role)->value('id'), 'user_id' => $user->id]);

    return $user;
}

/** @param array<string, mixed> $overrides */
function applicationFor(?User $applicant, string $status = 'submitted', array $overrides = []): B2bApplication
{
    $factory = $status === 'rejected' ? B2bApplication::factory()->rejected() : B2bApplication::factory();

    return $factory->create(array_merge([
        'applicant_user_id' => $applicant?->id,
        'company_name' => 'Harbour Stores Ltd',
        'registration_number' => '01234567',
        'contact_name' => 'Priya Shah',
        'contact_email' => $applicant->email ?? 'orders@harbour.example',
        'contact_phone' => '0117 496 0000',
        'business_type' => 'convenience',
        'address' => ['line1' => '1 Quay Street', 'line2' => null, 'city' => 'Bristol', 'county' => null, 'postcode' => 'BS1 4DJ', 'country_code' => 'GB'],
        'status' => $status,
    ], $overrides));
}

function applicationAudit(B2bApplication $application): Collection
{
    return AuditLog::query()->where('subject_type', 'b2b_application')->where('subject_id', $application->id)->orderBy('id')->get();
}

function applicationNotices(User $user, NotificationKey $key): Collection
{
    return DB::table('notification_log')->where('notification_key', $key->value)->where('user_id', $user->id)->get();
}

function accountCodeNext(): int
{
    return NumberSequence::query()->findOrFail('account_code')->next_value;
}

/**
 * @param  list<string>  $keys
 * @return list<string>
 */
function pricingKeysForgotten(array $keys): array
{
    return array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'pricing:lists:')));
}

function netThirtyTerms(PriceTier $tier, int $creditLimitMinor = 250050): ApprovalTerms
{
    return new ApprovalTerms($tier->id, PaymentTerms::Net30, $creditLimitMinor);
}

it('starts review, requests information, resumes and rejects from the detail page, auditing each step', function () {
    $admin = reviewStaff();
    $applicant = User::factory()->create();
    $application = applicationFor($applicant);
    $this->actingAs($admin);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->assertActionVisible('startReview')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('requestInfo')
        ->assertActionHidden('resumeReview')
        ->callAction('startReview')
        ->assertHasNoActionErrors();
    expect($application->fresh()->status)->toBe('in_review')
        ->and($application->fresh()->reviewer_user_id)->toBe($admin->id);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->assertActionHidden('startReview')
        ->callAction('requestInfo', data: ['info_request' => 'Please send a recent business bank statement.'])
        ->assertHasNoActionErrors();
    expect($application->fresh()->status)->toBe('info_requested')
        ->and($application->fresh()->info_request)->toBe('Please send a recent business bank statement.')
        ->and(applicationNotices($applicant, NotificationKey::ApplicationInfoRequested))->toHaveCount(1);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->assertActionHidden('approve')
        ->callAction('resumeReview')
        ->assertHasNoActionErrors();
    expect($application->fresh()->status)->toBe('in_review');

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('reject', data: ['rejection_category' => 'business_not_verified', 'review_note' => 'Trading address could not be verified.', 'remediable' => false])
        ->assertHasNoActionErrors();

    $fresh = $application->fresh();
    expect($fresh->status)->toBe('rejected')
        ->and($fresh->review_note)->toBe('Trading address could not be verified.')
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($fresh->company_id)->toBeNull()
        ->and(Company::query()->count())->toBe(0)
        ->and(applicationNotices($applicant, NotificationKey::ApplicationRejected))->toHaveCount(1);

    $audit = applicationAudit($application);
    expect($audit->pluck('action')->all())->toBe([
        'application.review_started', 'application.info_requested', 'application.review_resumed', 'application.rejected',
    ])
        ->and($audit->every(fn (AuditLog $entry) => $entry->actor_user_id === $admin->id && $entry->event_family === 'permission' && $entry->company_id === null))->toBeTrue()
        ->and($audit->pluck('before')->all())->toBe([['status' => 'submitted'], ['status' => 'in_review'], ['status' => 'info_requested'], ['status' => 'in_review']])
        ->and($audit->pluck('after')->all())->toEqual([
            ['status' => 'in_review'], ['status' => 'info_requested'], ['status' => 'in_review'],
            ['status' => 'rejected', 'remediable' => false, 'rejection_category' => 'business_not_verified'],
        ]);
});

it('approves: creates the company, links the applicant as owner, copies the address, audits, notifies and flushes pricing', function () {
    $mainland = DeliveryZone::factory()->create(['code' => 'GB_MAINLAND', 'country_code' => 'GB']);
    $admin = reviewStaff();
    $applicant = User::factory()->create();
    $application = applicationFor($applicant, 'in_review', ['vat_number' => 'GB123456789']);
    $this->actingAs($admin);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('approve', data: ['price_tier_id' => $this->gold->id, 'payment_terms' => 'net30', 'credit_limit' => '2500.50'])
        ->assertHasNoActionErrors();

    $company = Company::query()->sole();
    expect($company->status)->toBe('approved')
        ->and($company->name)->toBe('Harbour Stores Ltd')
        ->and($company->vat_number)->toBe('GB123456789')
        ->and($company->registration_number)->toBe('01234567')
        ->and($company->price_tier_id)->toBe($this->gold->id)
        ->and($company->payment_terms)->toBe('net30')
        ->and($company->credit_limit_minor)->toBe(250050)
        ->and($company->credit_used_minor)->toBe(0)
        ->and($company->account_code)->toBe('ACC-000001')
        ->and($company->approved_at)->not->toBeNull()
        ->and($company->getAttribute('approved_by_user_id'))->toBe($admin->id)
        ->and(accountCodeNext())->toBe(2);

    $membership = CompanyUser::query()->where('company_id', $company->id)->sole();
    expect($membership->user_id)->toBe($applicant->id)
        ->and($membership->role)->toBe('owner')
        ->and($membership->is_default_contact)->toBeTrue()
        ->and(User::query()->count())->toBe(2)
        ->and(CompanyMemberships::ids($applicant->fresh()))->toBe([$company->id]);

    $address = Address::query()->where('company_id', $company->id)->sole();
    expect($address->only(['label', 'contact_name', 'phone', 'line1', 'line2', 'city', 'county', 'postcode', 'country_code', 'address_type', 'is_default', 'delivery_zone_id']))->toBe([
        'label' => 'Trading address', 'contact_name' => 'Priya Shah', 'phone' => '0117 496 0000',
        'line1' => '1 Quay Street', 'line2' => null, 'city' => 'Bristol', 'county' => null, 'postcode' => 'BS1 4DJ',
        'country_code' => 'GB', 'address_type' => 'both', 'is_default' => true, 'delivery_zone_id' => $mainland->id,
    ]);

    $fresh = $application->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->company_id)->toBe($company->id)
        ->and($fresh->granted_tier_id)->toBe($this->gold->id)
        ->and($fresh->reviewer_user_id)->toBe($admin->id)
        ->and($fresh->reviewed_at)->not->toBeNull();

    $approved = applicationAudit($application)->sole();
    expect($approved->action)->toBe('application.approved')
        ->and($approved->company_id)->toBe($company->id)
        ->and($approved->before)->toBe(['status' => 'in_review'])
        ->and($approved->after)->toEqual([
            'status' => 'approved', 'company_id' => $company->id, 'owner_user_id' => $applicant->id,
            'price_tier_id' => $this->gold->id, 'payment_terms' => 'net30', 'credit_limit_minor' => 250050,
        ]);

    $credit = AuditLog::query()->where('action', 'credit_limit.changed')->sole();
    expect($credit->event_family)->toBe('credit_limit')
        ->and($credit->subject_type)->toBe('company')
        ->and($credit->subject_id)->toBe($company->id)
        ->and($credit->before)->toBe(['credit_limit_minor' => 0])
        ->and($credit->after)->toBe(['credit_limit_minor' => 250050]);

    expect(applicationNotices($applicant, NotificationKey::ApplicationApproved))->toHaveCount(1)
        ->and($this->forgotten)->toContain(PricingCache::companyListsKey($company->id));
});

it('approves on prepay with no credit and writes no credit limit audit', function () {
    $admin = reviewStaff();
    $application = applicationFor(User::factory()->create(), 'in_review');

    $company = app(ApplicationReviewService::class)->approve($application, $admin, new ApprovalTerms($this->bronze->id, PaymentTerms::Prepay, 0));

    expect($company->payment_terms)->toBe('prepay')
        ->and($company->credit_limit_minor)->toBe(0)
        ->and(AuditLog::query()->where('action', 'credit_limit.changed')->exists())->toBeFalse();
});

it('lets an accounts reviewer take an application through to approval with credit', function () {
    $accounts = reviewStaff('accounts');
    $applicant = User::factory()->create();
    $application = applicationFor($applicant);
    $service = app(ApplicationReviewService::class);
    $this->actingAs($accounts);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('startReview')
        ->assertHasNoActionErrors();
    expect($accounts->can('grantCredit', $application->fresh()))->toBeTrue();

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('approve', data: ['price_tier_id' => $this->gold->id, 'payment_terms' => 'net30', 'credit_limit' => '1000'])
        ->assertHasNoActionErrors();

    $company = Company::query()->sole();
    expect($company->credit_limit_minor)->toBe(100000)
        ->and($company->getAttribute('approved_by_user_id'))->toBe($accounts->id)
        ->and(applicationAudit($application)->pluck('actor_user_id')->unique()->all())->toBe([$accounts->id])
        ->and(AuditLog::query()->where('action', 'credit_limit.changed')->sole()->actor_user_id)->toBe($accounts->id);

    $rejected = applicationFor(User::factory()->create(), 'in_review');
    $service->reject($rejected, $accounts, 'Could not verify the business.', RejectionCategory::BusinessNotVerified, true);
    expect($rejected->fresh()->status)->toBe('rejected');
});

it('refuses a credit limit above zero to a reviewer without grantCredit, even under lock', function () {
    $admin = reviewStaff();
    $service = app(ApplicationReviewService::class);
    $withCredit = applicationFor(User::factory()->create(), 'in_review');
    $withoutCredit = applicationFor(User::factory()->create(), 'in_review');
    Gate::before(fn (User $user, string $ability) => $ability === 'grantCredit' ? false : null);

    expect(fn () => $service->approve($withCredit, $admin, netThirtyTerms($this->gold, 100)))->toThrow(AuthorizationException::class);
    expect($withCredit->fresh()->status)->toBe('in_review')
        ->and(Company::query()->exists())->toBeFalse()
        ->and(accountCodeNext())->toBe(1);

    $company = $service->approve($withoutCredit, $admin, netThirtyTerms($this->gold, 0));
    expect($company->credit_limit_minor)->toBe(0)
        ->and(AuditLog::query()->where('action', 'credit_limit.changed')->exists())->toBeFalse();
});

it('refuses every transition the state machine does not allow', function () {
    $admin = reviewStaff();
    $service = app(ApplicationReviewService::class);
    $attempts = [
        'startReview' => fn (B2bApplication $a) => $service->startReview($a, $admin),
        'requestInfo' => fn (B2bApplication $a) => $service->requestInfo($a, $admin, 'Please send ID.'),
        'resumeReview' => fn (B2bApplication $a) => $service->resumeReview($a, $admin),
        'approve' => fn (B2bApplication $a) => $service->approve($a, $admin, netThirtyTerms($this->gold)),
        'reject' => fn (B2bApplication $a) => $service->reject($a, $admin, 'No.', RejectionCategory::Other, false),
    ];
    $allowedFrom = ['startReview' => 'submitted', 'requestInfo' => 'in_review', 'resumeReview' => 'info_requested', 'approve' => 'in_review', 'reject' => 'in_review'];

    foreach (['submitted', 'in_review', 'info_requested', 'approved', 'rejected', 'withdrawn'] as $status) {
        foreach ($attempts as $ability => $attempt) {
            if ($allowedFrom[$ability] === $status) {
                continue;
            }
            $application = applicationFor(User::factory()->create(), $status);

            expect($admin->can($ability, $application))->toBeFalse();
            expect(fn () => $attempt($application))->toThrow(AuthorizationException::class);
            expect($application->fresh()->status)->toBe($status)
                ->and(applicationAudit($application))->toBeEmpty();
        }
    }

    expect(Company::query()->exists())->toBeFalse()
        ->and(accountCodeNext())->toBe(1);
});

it('lists open applications oldest first, for admin and accounts reviewers only', function () {
    $admin = reviewStaff();
    $older = applicationFor(User::factory()->create(), 'submitted', ['submitted_at' => now()->subDays(3)]);
    $newer = applicationFor(User::factory()->create(), 'info_requested', ['submitted_at' => now()->subDay()]);
    $decided = applicationFor(User::factory()->create(), 'rejected');
    $this->actingAs($admin);

    $this->get('/admin/trade-applications')->assertOk();
    $this->get('/admin/trade-applications/'.$older->id)->assertOk()->assertSee('Harbour Stores Ltd');
    Livewire::test(ListTradeApplications::class)
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true)
        ->assertCanNotSeeTableRecords([$decided]);

    $this->actingAs(reviewStaff('accounts'))->get('/admin/trade-applications')->assertOk();
    $this->get('/admin/trade-applications/'.$older->id)->assertOk();

    foreach (['purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $this->actingAs(reviewStaff($role))->get('/admin/trade-applications')->assertForbidden();
        $this->get('/admin/trade-applications/'.$older->id)->assertForbidden();
    }
    $this->actingAs(User::factory()->create())->get('/admin/trade-applications')->assertForbidden();
});

it('denies review to other staff roles, customers and inactive reviewers', function () {
    $application = applicationFor(User::factory()->create(), 'in_review');
    $service = app(ApplicationReviewService::class);

    $actors = [User::factory()->create(), reviewStaff('admin', 'suspended'), reviewStaff('accounts', 'suspended')];
    foreach (['purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $actors[] = reviewStaff($role);
    }

    foreach ($actors as $actor) {
        expect($actor->can('approve', $application))->toBeFalse()
            ->and($actor->can('grantCredit', $application))->toBeFalse();
        expect(fn () => $service->approve($application, $actor, netThirtyTerms($this->gold)))->toThrow(AuthorizationException::class);
        expect(fn () => $service->reject($application, $actor, 'No.', true))->toThrow(AuthorizationException::class);
        expect(fn () => $service->requestInfo($application, $actor, 'More please.'))->toThrow(AuthorizationException::class);
    }

    expect($application->fresh()->status)->toBe('in_review')
        ->and(applicationAudit($application))->toBeEmpty();
});

it('offers no review of staff-entered applications or of a reviewer\'s own', function () {
    $admin = reviewStaff();
    $staffEntered = applicationFor(null, 'in_review');
    $own = applicationFor($admin, 'in_review');
    $service = app(ApplicationReviewService::class);
    $this->actingAs($admin);

    Livewire::test(ViewTradeApplication::class, ['record' => $staffEntered->id])
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('requestInfo');
    expect(fn () => $service->approve($staffEntered, $admin, netThirtyTerms($this->gold)))->toThrow(AuthorizationException::class);
    expect(fn () => $service->approve($own, $admin, netThirtyTerms($this->gold)))->toThrow(AuthorizationException::class);

    expect(Company::query()->exists())->toBeFalse();
});

it('refuses approval when the applicant cannot own the account, creating nothing', function () {
    $admin = reviewStaff();
    $service = app(ApplicationReviewService::class);
    Company::factory()->create(['name' => 'Existing Wholesale', 'account_code' => 'ACC-EXIST', 'vat_number' => 'GB999999973']);

    $cases = [
        'The applicant has not confirmed their email address yet.' => applicationFor(User::factory()->unverified()->create(), 'in_review'),
        'The applicant\'s account is not active' => applicationFor(User::factory()->create(['status' => 'suspended']), 'in_review'),
        'is already on file for Existing Wholesale (ACC-EXIST)' => applicationFor(User::factory()->create(), 'in_review', ['vat_number' => 'GB999999973']),
        'trading address is incomplete' => applicationFor(User::factory()->create(), 'in_review', ['address' => ['line1' => '1 Quay Street']]),
    ];

    foreach ($cases as $message => $application) {
        expect(fn () => $service->approve($application, $admin, netThirtyTerms($this->gold)))->toThrow(ValidationException::class, $message);
        expect($application->fresh()->status)->toBe('in_review')
            ->and(applicationAudit($application))->toBeEmpty();
    }

    expect(Company::query()->count())->toBe(1)
        ->and(accountCodeNext())->toBe(1)
        ->and(DB::table('notification_log')->where('notification_key', NotificationKey::ApplicationApproved->value)->exists())->toBeFalse();
});

it('lets only one of two administrators decide, re-reading the application and actor under lock', function () {
    $first = reviewStaff();
    $second = reviewStaff();
    $service = app(ApplicationReviewService::class);
    $application = applicationFor(User::factory()->create(), 'in_review');
    $stale = B2bApplication::query()->findOrFail($application->id);
    $staleSecond = User::query()->findOrFail($second->id);

    $service->approve($application, $first, netThirtyTerms($this->gold));

    expect($stale->status)->toBe('in_review');
    expect(fn () => $service->approve($stale, $second, netThirtyTerms($this->bronze)))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reject($stale, $second, 'Too late.', false))->toThrow(AuthorizationException::class);

    app(StaffSuspensionService::class)->suspend($second, $first);
    $submitted = applicationFor(User::factory()->create());
    expect(fn () => $service->startReview($submitted, $staleSecond))->toThrow(AuthorizationException::class);

    expect(Company::query()->count())->toBe(1)
        ->and(Company::query()->sole()->price_tier_id)->toBe($this->gold->id)
        ->and(applicationAudit($application)->pluck('action')->all())->toBe(['application.approved'])
        ->and($submitted->fresh()->status)->toBe('submitted');
});

it('rolls back an approval completely, including the account number', function () {
    $admin = reviewStaff();
    $applicant = User::factory()->create();
    $application = applicationFor($applicant, 'in_review');

    try {
        DB::transaction(function () use ($application, $admin): void {
            app(ApplicationReviewService::class)->approve($application, $admin, netThirtyTerms($this->gold));
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated failure');
    }

    expect($application->fresh()->status)->toBe('in_review')
        ->and($application->fresh()->company_id)->toBeNull()
        ->and(Company::query()->exists())->toBeFalse()
        ->and(CompanyUser::query()->exists())->toBeFalse()
        ->and(Address::query()->exists())->toBeFalse()
        ->and(accountCodeNext())->toBe(1)
        ->and(AuditLog::query()->exists())->toBeFalse()
        ->and(DB::table('notification_log')->exists())->toBeFalse()
        ->and(pricingKeysForgotten($this->forgotten))->toBeEmpty();
});

it('sends notices and flushes pricing only after the decision commits', function () {
    $admin = reviewStaff();
    $applicant = User::factory()->create();
    $application = applicationFor($applicant, 'in_review');
    $rejectedApplicant = User::factory()->create();
    $toReject = applicationFor($rejectedApplicant, 'in_review');

    DB::transaction(function () use ($application, $toReject, $admin, $applicant, $rejectedApplicant): void {
        $service = app(ApplicationReviewService::class);
        $service->approve($application, $admin, netThirtyTerms($this->gold));
        $service->reject($toReject, $admin, 'Duplicate of an existing account.', RejectionCategory::DuplicateAccount, true);

        expect(applicationNotices($applicant, NotificationKey::ApplicationApproved))->toBeEmpty()
            ->and(applicationNotices($rejectedApplicant, NotificationKey::ApplicationRejected))->toBeEmpty()
            ->and(pricingKeysForgotten($this->forgotten))->toBeEmpty();
    });

    expect(applicationNotices($applicant, NotificationKey::ApplicationApproved))->toHaveCount(1)
        ->and(applicationNotices($rejectedApplicant, NotificationKey::ApplicationRejected))->toHaveCount(1)
        ->and($this->forgotten)->toContain(PricingCache::companyListsKey(Company::query()->sole()->id));
});

it('keeps the internal reason out of the rejection email and puts the terms in the approval email', function () {
    $admin = reviewStaff();
    $service = app(ApplicationReviewService::class);
    $rejected = applicationFor($rejectedApplicant = User::factory()->create(), 'in_review');
    $approved = applicationFor($approvedApplicant = User::factory()->create(), 'in_review', ['company_name' => 'Quay Traders Ltd']);

    $remediableApplication = applicationFor($remediableApplicant = User::factory()->create(), 'in_review');

    $service->reject($rejected, $admin, 'Director is on the internal watch list.', RejectionCategory::CreditOrRiskConcern, false);
    $service->reject($remediableApplication, $admin, 'Needs a VAT certificate.', RejectionCategory::BusinessNotVerified, true, 'Please reapply with your VAT certificate attached.');
    $company = $service->approve($approved, $admin, netThirtyTerms($this->gold));

    $cooling = (new ApplicationRejected($rejected->id))->content(Recipient::user($rejectedApplicant));
    $text = implode(' ', [$cooling->subject, ...$cooling->paragraphs]);
    expect($text)->not->toContain('watch list')
        ->and($text)->not->toContain('Credit or risk')
        ->and($text)->toContain(ApplicationRejected::DEFAULT_MESSAGE)
        ->and($text)->toContain('apply again from '.$rejected->fresh()->reapply_after->timezone('Europe/London')->format('j F Y'));

    $remediable = (new ApplicationRejected($remediableApplication->id))->content(Recipient::user($remediableApplicant));
    expect($remediable->paragraphs)->toContain('Please reapply with your VAT certificate attached.')
        ->and(implode(' ', $remediable->paragraphs))->not->toContain('apply again from')
        ->and(implode(' ', $remediable->paragraphs))->not->toContain(ApplicationRejected::DEFAULT_MESSAGE);

    $welcome = (new ApplicationApproved($approved->id))->content(Recipient::user($approvedApplicant));
    expect($welcome->facts)->toBe([
        ['label' => 'Account code', 'value' => $company->account_code],
        ['label' => 'Price tier', 'value' => 'Gold'],
        ['label' => 'Payment terms', 'value' => '30 days net'],
        ['label' => 'Credit limit', 'value' => '£2,500.50'],
    ]);
});

it('accepts only the approved application and credit limit audit shapes', function () {
    $entry = fn (AuditAction $action, array $before, array $after, ?int $companyId = null, string $subjectType = 'b2b_application', int $subjectId = 5, ?string $reason = null) => new AuditEntry(
        action: $action,
        actorType: 'user',
        actorUserId: 1,
        companyId: $companyId,
        subjectType: $subjectType,
        subjectId: $subjectId,
        before: $before,
        after: $after,
        reason: $reason,
    );
    $approvedAfter = ['status' => 'approved', 'company_id' => 7, 'owner_user_id' => 2, 'price_tier_id' => 3, 'payment_terms' => 'net30', 'credit_limit_minor' => 1000];
    $logger = new AuditLogger;

    foreach ([
        $entry(AuditAction::ApplicationReviewStarted, ['status' => 'in_review'], ['status' => 'in_review']),
        $entry(AuditAction::ApplicationInfoRequested, ['status' => 'in_review'], ['status' => 'info_requested', 'info_request' => 'Send ID']),
        $entry(AuditAction::ApplicationReviewResumed, ['status' => 'submitted'], ['status' => 'in_review']),
        $entry(AuditAction::ApplicationRejected, ['status' => 'in_review'], ['status' => 'rejected']),
        $entry(AuditAction::ApplicationRejected, ['status' => 'in_review'], ['status' => 'rejected', 'remediable' => false]),
        $entry(AuditAction::ApplicationRejected, ['status' => 'in_review'], ['status' => 'rejected', 'remediable' => false, 'rejection_category' => 'watch_list']),
        $entry(AuditAction::ApplicationRejected, ['status' => 'in_review'], ['status' => 'rejected', 'remediable' => false, 'rejection_category' => 'other', 'review_note' => 'Watch list']),
        $entry(AuditAction::ApplicationRejected, ['status' => 'in_review'], ['status' => 'rejected', 'remediable' => false, 'rejection_category' => 'other'], reason: 'Watch list'),
        $entry(AuditAction::ApplicationApproved, ['status' => 'in_review'], $approvedAfter, companyId: 8),
        $entry(AuditAction::ApplicationApproved, ['status' => 'in_review'], [...$approvedAfter, 'payment_terms' => 'net90'], companyId: 7),
        $entry(AuditAction::ApplicationApproved, ['status' => 'in_review'], [...$approvedAfter, 'credit_limit_minor' => -1], companyId: 7),
        $entry(AuditAction::ApplicationApproved, ['status' => 'in_review'], $approvedAfter, companyId: 7, subjectType: 'company'),
        $entry(AuditAction::CreditLimitChanged, ['credit_limit_minor' => 0], ['credit_limit_minor' => 0], companyId: 7, subjectType: 'company', subjectId: 7),
        $entry(AuditAction::CreditLimitChanged, ['credit_limit_minor' => 0], ['credit_limit_minor' => 1000], companyId: 8, subjectType: 'company', subjectId: 7),
        $entry(AuditAction::CreditLimitChanged, ['credit_limit_minor' => 0], ['credit_limit_minor' => '10.00'], companyId: 7, subjectType: 'company', subjectId: 7),
    ] as $invalid) {
        expect(fn () => $logger->record($invalid))->toThrow(InvalidArgumentException::class);
    }

    $company = Company::factory()->create();
    $logger->record($entry(AuditAction::ApplicationRejected, ['status' => 'in_review'], ['status' => 'rejected', 'remediable' => true, 'rejection_category' => 'duplicate_account']));
    $logger->record($entry(AuditAction::ApplicationApproved, ['status' => 'in_review'], [...$approvedAfter, 'company_id' => $company->id], companyId: $company->id));
    $logger->record($entry(AuditAction::CreditLimitChanged, ['credit_limit_minor' => 0], ['credit_limit_minor' => 1000], companyId: $company->id, subjectType: 'company', subjectId: $company->id));

    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['application.rejected', 'application.approved', 'credit_limit.changed']);
});
