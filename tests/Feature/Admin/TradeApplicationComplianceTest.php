<?php

use App\Domain\Accounts\ApplicationReviewService;
use App\Domain\Accounts\ApplicationSettings;
use App\Domain\Accounts\ApprovalTerms;
use App\Domain\Accounts\RejectionCategory;
use App\Domain\Billing\PaymentTerms;
use App\Domain\Notifications\Notices\ApplicationRejected;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Filament\Resources\TradeApplicationResource\Pages\ViewTradeApplication;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\NumberSequence;
use App\Models\PriceTier;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SystemConfiguration;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
    $this->tier = PriceTier::factory()->default()->create(['code' => 'bronze', 'name' => 'Bronze']);
    $this->admin = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::query()->where('code', 'admin')->value('id'), 'user_id' => $this->admin->id]);
});

/** @param array<string, mixed> $overrides */
function complianceApplication(string $status = 'in_review', array $overrides = []): B2bApplication
{
    $applicant = User::factory()->create();

    return B2bApplication::factory()->create([
        'applicant_user_id' => $applicant->id,
        'contact_email' => $applicant->email,
        'contact_name' => 'Nia Evans',
        'company_name' => 'Valley Supplies Ltd',
        'legal_form' => 'limited_company',
        'registration_number' => '01234567',
        'business_type' => 'catering',
        'address' => ['line1' => '4 Castle Street', 'city' => 'Cardiff', 'postcode' => 'CF10 1BH', 'country_code' => 'GB'],
        'status' => $status,
        ...$overrides,
    ]);
}

it('rejects with a category, message and cooling period taken from the setting, auditing the category only', function () {
    SystemConfiguration::query()->where('config_key', ApplicationSettings::REAPPLY_COOLING_DAYS)->update(['value_int' => 30]);
    $application = complianceApplication();
    $this->actingAs($this->admin);

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('reject', data: ['review_note' => 'No evidence of trading.', 'remediable' => false])
        ->assertHasActionErrors(['rejection_category' => 'required']);
    expect($application->fresh()->status)->toBe('in_review');

    Livewire::test(ViewTradeApplication::class, ['record' => $application->id])
        ->callAction('reject', data: [
            'rejection_category' => 'not_a_trade_business',
            'review_note' => 'No evidence of trading.',
            'applicant_message' => 'We could not confirm you trade from a business premises.',
            'remediable' => false,
        ])
        ->assertHasNoActionErrors();

    $fresh = $application->fresh();
    expect($fresh->status)->toBe('rejected')
        ->and($fresh->rejection_category)->toBe('not_a_trade_business')
        ->and($fresh->rejection_remediable)->toBeFalse()
        ->and($fresh->applicant_message)->toBe('We could not confirm you trade from a business premises.')
        ->and($fresh->review_note)->toBe('No evidence of trading.')
        ->and($fresh->reapply_after?->getTimestamp())->toBe($fresh->reviewed_at?->copy()->addDays(30)->getTimestamp());

    $audit = AuditLog::query()->where('action', 'application.rejected')->sole();
    expect($audit->after)->toEqual(['status' => 'rejected', 'remediable' => false, 'rejection_category' => 'not_a_trade_business'])
        ->and(json_encode($audit->after))->not->toContain('business premises');

    $mail = (new ApplicationRejected($application->id))->content(Recipient::user(User::query()->findOrFail($fresh->applicant_user_id)));
    expect($mail->paragraphs)->toContain('We could not confirm you trade from a business premises.')
        ->and(implode(' ', $mail->paragraphs))->not->toContain('No evidence of trading')
        ->and(implode(' ', $mail->paragraphs))->toContain('apply again from '.$fresh->reapply_after->timezone('Europe/London')->format('j F Y'));
    expect(DB::table('notification_log')->where('notification_key', NotificationKey::ApplicationRejected->value)->value('template_version'))->toBe('2');
});

it('keeps no re-application date for a remediable rejection, and sends the neutral default without a message', function () {
    $application = complianceApplication();

    app(ApplicationReviewService::class)->reject($application, $this->admin, 'Missing VAT certificate.', RejectionCategory::BusinessNotVerified, true, '   ');

    $fresh = $application->fresh();
    expect($fresh->rejection_remediable)->toBeTrue()
        ->and($fresh->reapply_after)->toBeNull()
        ->and($fresh->applicant_message)->toBeNull();

    $mail = (new ApplicationRejected($application->id))->content(Recipient::user(User::query()->findOrFail($fresh->applicant_user_id)));
    expect($mail->paragraphs)->toContain(ApplicationRejected::DEFAULT_MESSAGE)
        ->and(implode(' ', $mail->paragraphs))->not->toContain('apply again from');

    expect(fn () => app(ApplicationReviewService::class)->reject(complianceApplication(), $this->admin, 'x', RejectionCategory::Other, true, str_repeat('a', 2001)))
        ->toThrow(ValidationException::class);
});

it('shows the legal form and terms acceptance on the review screen, "not recorded" for older applications', function () {
    $terms = TermsVersion::factory()->create(['version' => '2026-10']);
    $accepted = complianceApplication('submitted');
    TermsAcceptance::query()->create([
        'terms_version_id' => $terms->id, 'user_id' => $accepted->applicant_user_id,
        'b2b_application_id' => $accepted->id, 'source' => 'trade_application', 'ip' => '203.0.113.9',
    ]);
    $legacy = complianceApplication('submitted', ['legal_form' => null, 'registration_number' => null]);
    $this->actingAs($this->admin);

    $this->get('/admin/trade-applications/'.$accepted->id)->assertOk()
        ->assertSee('Limited company')
        ->assertSee('Version 2026-10')
        ->assertSee('203.0.113.9');
    $this->get('/admin/trade-applications/'.$legacy->id)->assertOk()
        ->assertSee('Accepted before terms were versioned — not recorded.')
        ->assertSee('Not recorded (applied before legal form was asked)');
});

it('copies the legal form to the company and stores the address postcode in standard form', function () {
    $application = complianceApplication('in_review', ['address' => ['line1' => '4 Castle Street', 'city' => 'Cardiff', 'postcode' => 'cf101bh']]);

    $company = app(ApplicationReviewService::class)->approve($application, $this->admin, new ApprovalTerms($this->tier->id, PaymentTerms::Prepay, 0), true);

    expect($company->fresh()->legal_form)->toBe('limited_company')
        ->and(Address::query()->where('company_id', $company->id)->sole()->postcode)->toBe('CF10 1BH');
});

it('turns a concurrent approval of the same VAT number into a friendly error, rolling back completely', function () {
    $application = complianceApplication('in_review', ['vat_number' => 'GB980780684']);
    $nextAccountCode = NumberSequence::query()->findOrFail('account_code')->next_value;

    // A second approval commits the same VAT number between this approval's
    // duplicate check and its insert — what companies_vat_uq exists to stop.
    Company::creating(function (Company $company): void {
        if ($company->vat_number === 'GB980780684') {
            DB::table('companies')->insert([
                'public_id' => (string) Str::ulid(), 'account_code' => 'ACC-RACE', 'name' => 'Racing Approval Ltd',
                'vat_number' => 'GB980780684', 'status' => 'approved',
            ]);
        }
    });

    expect(fn () => app(ApplicationReviewService::class)->approve($application, $this->admin, new ApprovalTerms($this->tier->id, PaymentTerms::Prepay, 0), true))
        ->toThrow(ValidationException::class, 'VAT number GB980780684 was approved for another account a moment ago');

    expect($application->fresh()->status)->toBe('in_review')
        ->and(Company::query()->where('vat_number', 'GB980780684')->exists())->toBeFalse()
        ->and(NumberSequence::query()->findOrFail('account_code')->next_value)->toBe($nextAccountCode)
        ->and(AuditLog::query()->where('action', 'application.approved')->exists())->toBeFalse()
        ->and(DB::table('notification_log')->exists())->toBeFalse();
});
