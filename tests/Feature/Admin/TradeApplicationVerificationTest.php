<?php

use App\Domain\Accounts\ApplicationDuplicates;
use App\Domain\Accounts\ApplicationReviewService;
use App\Domain\Accounts\ApplicationSettings;
use App\Domain\Accounts\ApprovalTerms;
use App\Domain\Accounts\BusinessVerification;
use App\Domain\Billing\PaymentTerms;
use App\Filament\Resources\TradeApplicationResource;
use App\Filament\Resources\TradeApplicationResource\Pages\ListTradeApplications;
use App\Filament\Resources\TradeApplicationResource\Pages\ViewTradeApplication;
use App\Jobs\VerifyApplicationBusiness;
use App\Models\AuditLog;
use App\Models\B2bApplication;
use App\Models\CompaniesHouseCheck;
use App\Models\Company;
use App\Models\PriceTier;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Models\VatNumberCheck;
use App\Rules\UkVatNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
    $this->tier = PriceTier::factory()->default()->create();
    $this->admin = verificationReviewer('admin');
});

function verificationReviewer(string $role, string $status = 'active'): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);
    RoleUser::create(['role_id' => Role::query()->where('code', $role)->value('id'), 'user_id' => $user->id]);

    return $user;
}

/** @param array<string, mixed> $overrides */
function checkedApplication(array $overrides = []): B2bApplication
{
    $applicant = User::factory()->create();

    return B2bApplication::factory()->create([
        'applicant_user_id' => $applicant->id,
        'contact_email' => $applicant->email,
        'company_name' => 'Harbour Wholesale Ltd',
        'legal_form' => 'limited_company',
        'registration_number' => '01234567',
        'vat_number' => 'GB980780684',
        'address' => ['line1' => '1 Quay Street', 'city' => 'Bristol', 'postcode' => 'BS1 4DJ'],
        'status' => 'in_review',
        ...$overrides,
    ]);
}

function vatEvidence(B2bApplication $application, string $outcome = 'valid', array $overrides = []): VatNumberCheck
{
    return VatNumberCheck::query()->create([
        'b2b_application_id' => $application->id,
        'vat_number' => $application->vat_number,
        'authority' => str_starts_with((string) $application->vat_number, 'XI') ? 'vies' : 'hmrc',
        'outcome' => $outcome,
        'registered_name' => $outcome === 'valid' ? 'HARBOUR WHOLESALE LTD' : null,
        'processed_at' => $outcome === 'unchecked' ? null : now(),
        'failure_reason' => $outcome === 'unchecked' ? 'unavailable' : null,
        'checked_at' => now(),
        ...$overrides,
    ]);
}

function companyEvidence(B2bApplication $application, string $outcome = 'found', ?string $status = 'active', ?string $type = 'ltd', array $overrides = []): CompaniesHouseCheck
{
    return CompaniesHouseCheck::query()->create([
        'b2b_application_id' => $application->id,
        'company_number' => $application->registration_number,
        'outcome' => $outcome,
        'company_status' => $outcome === 'found' ? $status : null,
        'company_type' => $outcome === 'found' ? $type : null,
        'registered_name' => $outcome === 'found' ? 'HARBOUR WHOLESALE LIMITED' : null,
        'failure_reason' => $outcome === 'unchecked' ? 'timeout' : null,
        'checked_at' => now(),
        ...$overrides,
    ]);
}

/** @return list<string> */
function warningCodes(B2bApplication $application): array
{
    return array_map(fn ($w) => $w->value, app(BusinessVerification::class)->assess($application)->warnings);
}

function approveChecked(B2bApplication $application, User $actor, bool $acknowledge = false): Company
{
    return app(ApplicationReviewService::class)->approve($application, $actor, new ApprovalTerms(PriceTier::query()->value('id'), PaymentTerms::Prepay, 0), $acknowledge);
}

it('passes clean, current evidence with no warnings', function () {
    $application = checkedApplication();
    vatEvidence($application);
    companyEvidence($application);

    $assessment = app(BusinessVerification::class)->assess($application);
    expect($assessment->warnings)->toBe([])
        ->and($assessment->refusal)->toBeNull()
        ->and($assessment->badge())->toBe('Verified');
});

it('warns about each shortfall in the VAT evidence', function () {
    $missing = checkedApplication();
    companyEvidence($missing);
    expect(warningCodes($missing))->toBe(['vat_not_checked']);

    $unchecked = checkedApplication();
    companyEvidence($unchecked);
    vatEvidence($unchecked, 'unchecked');
    expect(warningCodes($unchecked))->toBe(['vat_not_checked']);

    $notFound = checkedApplication();
    companyEvidence($notFound);
    vatEvidence($notFound, 'not_found');
    expect(warningCodes($notFound))->toBe(['vat_not_found']);

    $stale = checkedApplication();
    companyEvidence($stale);
    vatEvidence($stale, 'valid', ['checked_at' => now()->subDays(31)]);
    expect(warningCodes($stale))->toBe(['vat_stale']);

    // The threshold is the system setting, not a constant.
    SystemConfiguration::query()->where('config_key', ApplicationSettings::VERIFICATION_MAX_AGE_DAYS)->update(['value_int' => 60]);
    expect(warningCodes($stale))->toBe([]);
});

it('refuses a limited company or LLP that Companies House reports missing, ended or insolvent', function (string $form, string $outcome, ?string $status) {
    $application = checkedApplication(['legal_form' => $form, 'registration_number' => $form === 'llp' ? 'OC123456' : '01234567']);
    vatEvidence($application);
    companyEvidence($application, $outcome, $status, $form === 'llp' ? 'llp' : 'ltd');

    expect(app(BusinessVerification::class)->assess($application)->refusal)->not->toBeNull();
    expect(fn () => approveChecked($application, $this->admin, acknowledge: true))->toThrow(ValidationException::class, 'cannot be approved');
    expect($application->fresh()->status)->toBe('in_review')
        ->and(Company::query()->exists())->toBeFalse();
})->with([
    'ltd not found' => ['limited_company', 'not_found', null],
    'llp not found' => ['llp', 'not_found', null],
    'dissolved' => ['limited_company', 'found', 'dissolved'],
    'removed' => ['limited_company', 'found', 'removed'],
    'closed' => ['limited_company', 'found', 'closed'],
    'converted-closed' => ['limited_company', 'found', 'converted-closed'],
    'liquidation' => ['limited_company', 'found', 'liquidation'],
    'administration' => ['llp', 'found', 'administration'],
    'receivership' => ['limited_company', 'found', 'receivership'],
    'insolvency-proceedings' => ['limited_company', 'found', 'insolvency-proceedings'],
]);

it('only warns for a voluntary arrangement, an unknown status, or forms Companies House need not know', function () {
    $cva = checkedApplication();
    vatEvidence($cva);
    companyEvidence($cva, 'found', 'voluntary-arrangement');
    expect(warningCodes($cva))->toBe(['companies_house_not_active']);

    $unknown = checkedApplication();
    vatEvidence($unknown);
    companyEvidence($unknown, 'found', 'a-status-added-next-year');
    expect(warningCodes($unknown))->toBe(['companies_house_not_active']);

    $partnership = checkedApplication(['legal_form' => 'partnership', 'registration_number' => 'LP123456']);
    vatEvidence($partnership);
    companyEvidence($partnership, 'found', 'dissolved', 'limited-partnership');
    expect(app(BusinessVerification::class)->assess($partnership)->refusal)->toBeNull()
        ->and(warningCodes($partnership))->toBe(['companies_house_not_active']);

    $other = checkedApplication(['legal_form' => 'other', 'registration_number' => 'CE123456']);
    vatEvidence($other);
    companyEvidence($other, 'not_found');
    expect(warningCodes($other))->toBe(['companies_house_not_found']);

    $mismatch = checkedApplication();
    vatEvidence($mismatch);
    companyEvidence($mismatch, 'found', 'active', 'llp');
    expect(warningCodes($mismatch))->toBe(['companies_house_type_mismatch']);

    $stale = checkedApplication();
    vatEvidence($stale);
    companyEvidence($stale, 'found', 'active', 'private-limited-guarant-nsc', ['checked_at' => now()->subDays(45)]);
    expect(warningCodes($stale))->toBe(['companies_house_stale']);

    $unchecked = checkedApplication();
    vatEvidence($unchecked);
    companyEvidence($unchecked, 'unchecked');
    expect(warningCodes($unchecked))->toBe(['companies_house_not_checked']);
});

it('goes by the latest check, so a newer result replaces a refusing one', function () {
    $application = checkedApplication();
    vatEvidence($application);
    companyEvidence($application, 'found', 'dissolved', 'ltd', ['checked_at' => now()->subDay()]);
    companyEvidence($application, 'found', 'active');

    expect(app(BusinessVerification::class)->assess($application)->refusal)->toBeNull();
});

it('approves past warnings only when acknowledged, recording both in the audit', function () {
    $application = checkedApplication();
    vatEvidence($application, 'not_found');
    companyEvidence($application, 'found', 'voluntary-arrangement');

    expect(fn () => approveChecked($application, $this->admin))->toThrow(ValidationException::class, 'tick to confirm');
    expect(Company::query()->exists())->toBeFalse();

    approveChecked($application, $this->admin, acknowledge: true);

    $after = AuditLog::query()->where('action', 'application.approved')->sole()->after;
    expect($after['verification_warnings'])->toBe('companies_house_not_active,vat_not_found')
        ->and($after['verification_acknowledged'])->toBeTrue();
});

it('records no warnings and no acknowledgement for clean evidence', function () {
    $application = checkedApplication();
    vatEvidence($application);
    companyEvidence($application);

    approveChecked($application, $this->admin);

    $after = AuditLog::query()->where('action', 'application.approved')->sole()->after;
    expect($after['verification_warnings'])->toBe('')
        ->and($after['verification_acknowledged'])->toBeFalse();
});

it('makes the acknowledgement tick required on the approve form only when there are warnings', function () {
    $application = checkedApplication();
    companyEvidence($application);
    $this->actingAs($this->admin);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('approve', data: ['price_tier_id' => $this->tier->id, 'payment_terms' => 'prepay', 'credit_limit' => '0'])
        ->assertHasActionErrors(['acknowledge_warnings' => 'accepted']);
    expect($application->fresh()->status)->toBe('in_review');

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('approve', data: ['price_tier_id' => $this->tier->id, 'payment_terms' => 'prepay', 'credit_limit' => '0', 'acknowledge_warnings' => true])
        ->assertHasNoActionErrors();
    expect($application->fresh()->status)->toBe('approved');
});

it('lets reviewers re-run the checks on an open application, auditing the request', function () {
    Queue::fake();
    $accounts = verificationReviewer('accounts');
    $application = checkedApplication();
    $this->actingAs($accounts);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->assertActionVisible('rerunChecks')
        ->callAction('rerunChecks')
        ->assertHasNoActionErrors();

    Queue::assertPushed(VerifyApplicationBusiness::class, fn (VerifyApplicationBusiness $job) => $job->applicationId === $application->id && $job->requestedByUserId === $accounts->id);
    $audit = AuditLog::query()->where('action', 'application.verification_requested')->sole();
    expect($audit->event_family)->toBe('permission')
        ->and($audit->actor_user_id)->toBe($accounts->id)
        ->and($audit->subject_id)->toBe($application->id)
        ->and($audit->after)->toBe(['checks' => 'companies_house,vat']);
});

it('refuses a re-run to non-reviewers, on decided applications and with nothing to check', function () {
    Queue::fake();
    $service = app(ApplicationReviewService::class);
    $open = checkedApplication();

    foreach ([verificationReviewer('warehouse'), verificationReviewer('admin', 'suspended'), User::factory()->create()] as $actor) {
        expect($actor->can('rerunChecks', $open))->toBeFalse();
        expect(fn () => $service->requestChecks($open, $actor))->toThrow(AuthorizationException::class);
    }
    foreach ([checkedApplication(['status' => 'approved']), checkedApplication(['vat_number' => null, 'registration_number' => null, 'legal_form' => 'sole_trader'])] as $application) {
        expect($this->admin->can('rerunChecks', $application))->toBeFalse();
    }

    Queue::assertNothingPushed();
    expect(AuditLog::query()->where('action', 'application.verification_requested')->exists())->toBeFalse();
});

it('shows the evidence with its age on the review screen, flagging stale checks', function () {
    $application = checkedApplication();
    vatEvidence($application, 'valid', ['consultation_number' => 'KFB-J8S-7XQ', 'checked_at' => now()->subDays(40)]);
    companyEvidence($application, 'found', 'active', 'ltd', ['incorporated_on' => '2019-03-14']);
    $this->actingAs($this->admin);

    $this->get('/admin/trade-applications/'.$application->id)->assertOk()
        ->assertSee('Consultation number: KFB-J8S-7XQ')
        ->assertSee('HARBOUR WHOLESALE LIMITED')
        ->assertSee('Incorporated 14 Mar 2019')
        ->assertSee('STALE: older than 30 days')
        ->assertSee('The VAT check is older than the evidence limit.');
});

it('badges the queue by the evidence and colours statuses', function () {
    $verified = checkedApplication();
    vatEvidence($verified);
    companyEvidence($verified);
    $unchecked = checkedApplication();
    $attention = checkedApplication();
    vatEvidence($attention, 'not_found');
    companyEvidence($attention);
    $this->actingAs($this->admin);

    Livewire::test(ListTradeApplications::class)
        ->assertTableColumnStateSet('checks', 'Verified', $verified)
        ->assertTableColumnStateSet('checks', 'Unchecked', $unchecked)
        ->assertTableColumnStateSet('checks', 'Needs attention', $attention);

    expect(array_map(TradeApplicationResource::statusColor(...), ['rejected', 'approved', 'in_review', 'info_requested', 'submitted', 'withdrawn']))
        ->toBe(['danger', 'success', 'warning', 'warning', 'gray', 'gray']);
});

it('accepts XI VAT numbers and flags an XI application whose digits match a GB account', function () {
    // The examples the form and the error message show must themselves be valid.
    expect(Validator::make(['v' => UkVatNumber::EXAMPLE_GB], ['v' => [new UkVatNumber]])->passes())->toBeTrue()
        ->and(Validator::make(['v' => UkVatNumber::EXAMPLE_XI], ['v' => [new UkVatNumber]])->passes())->toBeTrue()
        ->and(Validator::make(['v' => 'XI980780684'], ['v' => [new UkVatNumber]])->passes())->toBeTrue()
        ->and(Validator::make(['v' => 'XI123456789'], ['v' => [new UkVatNumber]])->passes())->toBeFalse()
        ->and(Validator::make(['v' => 'FR980780684'], ['v' => [new UkVatNumber]])->passes())->toBeFalse();

    Company::factory()->create(['name' => 'Harbour Wholesale (GB)', 'account_code' => 'ACC-GB', 'vat_number' => 'GB980780684']);
    $application = checkedApplication(['vat_number' => 'XI980780684']);

    expect(app(ApplicationDuplicates::class)->flags($application))
        ->toContain('VAT number matches account Harbour Wholesale (GB) (ACC-GB, approved), as GB980780684.');
});
