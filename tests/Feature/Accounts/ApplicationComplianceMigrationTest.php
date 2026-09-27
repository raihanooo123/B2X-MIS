<?php

use App\Domain\Accounts\LegalForm;
use App\Domain\Accounts\RejectionCategory;
use App\Domain\Accounts\TermsAcceptanceSource;
use App\Domain\Accounts\TermsKind;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 02 §25.2–25.3 backfills. Each test rolls one migration back, writes rows
 * as they existed before it, and runs it again — inside the test's
 * transaction, which Postgres makes safe for DDL.
 */
function complianceMigration(string $name): object
{
    // Laravel's migrator caches each migration it requires; so does this.
    static $migrations = [];

    return $migrations[$name] ??= require database_path("migrations/{$name}.php");
}

function legacyApplication(array $attributes): int
{
    return (int) DB::table('b2b_applications')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'company_name' => 'Legacy Traders',
        'contact_name' => 'Pat Legacy',
        'contact_email' => Str::random(8).'@legacy.example',
        'address' => json_encode(['line1' => '1 Old Road', 'city' => 'Leeds', 'postcode' => 'LS1 1AA']),
        'status' => 'submitted',
        ...$attributes,
    ]);
}

function legacyRejectionAudit(int $applicationId, bool $remediable, User $actor): void
{
    DB::table('audit_log')->insert([
        'event_family' => 'permission',
        'action' => 'application.rejected',
        'actor_type' => 'user',
        'actor_user_id' => $actor->id,
        'subject_type' => 'b2b_application',
        'subject_id' => $applicationId,
        'before' => json_encode(['status' => 'in_review']),
        'after' => json_encode(['status' => 'rejected', 'remediable' => $remediable]),
    ]);
}

it('backfills existing rejections from their audit rows, conservatively when there is none', function () {
    $migration = complianceMigration('2026_10_14_090200_add_rejection_outcome_to_b2b_applications');
    $migration->down();
    $actor = User::factory()->create();

    $reviewedAt = now()->subDays(10)->startOfSecond();
    $remediable = legacyApplication(['status' => 'rejected', 'reviewed_at' => $reviewedAt]);
    legacyRejectionAudit($remediable, true, $actor);
    $cooling = legacyApplication(['status' => 'rejected', 'reviewed_at' => $reviewedAt]);
    legacyRejectionAudit($cooling, false, $actor);
    $submittedAt = now()->subDays(40)->startOfSecond();
    $unaudited = legacyApplication(['status' => 'rejected', 'reviewed_at' => null, 'submitted_at' => $submittedAt]);
    $open = legacyApplication(['status' => 'in_review']);

    $migration->up();

    $row = fn (int $id) => DB::table('b2b_applications')->where('id', $id)->first();
    expect($row($remediable)->rejection_category)->toBe('other')
        ->and($row($remediable)->rejection_remediable)->toBeTrue()
        ->and($row($remediable)->reapply_after)->toBeNull()
        ->and($row($remediable)->applicant_message)->toBeNull()
        ->and($row($cooling)->rejection_remediable)->toBeFalse()
        ->and(strtotime($row($cooling)->reapply_after))->toBe($reviewedAt->copy()->addDays(90)->getTimestamp())
        ->and($row($unaudited)->rejection_remediable)->toBeFalse()
        ->and(strtotime($row($unaudited)->reapply_after))->toBe($submittedAt->copy()->addDays(90)->getTimestamp())
        ->and($row($open)->rejection_category)->toBeNull()
        ->and($row($open)->reapply_after)->toBeNull()
        ->and(DB::table('system_configurations')->where('config_key', 'applications.reapply_cooling_days')->value('value_int'))->toBe(90);
});

it('enforces the rejection outcome once migrated', function () {
    $rejected = legacyApplication(['status' => 'rejected', 'reviewed_at' => now(), 'rejection_category' => 'other', 'rejection_remediable' => true]);

    foreach ([
        ['rejection_category' => null],
        ['rejection_category' => 'watch_list'],
        ['rejection_remediable' => false],
        ['reapply_after' => now()->addDay()],
        ['applicant_message' => ''],
    ] as $change) {
        expect(fn () => DB::transaction(fn () => DB::table('b2b_applications')->where('id', $rejected)->update($change)))
            ->toThrow(QueryException::class);
    }

    expect(fn () => DB::transaction(fn () => legacyApplication(['status' => 'in_review', 'rejection_category' => 'other'])))
        ->toThrow(QueryException::class, 'b2b_applications_rejection_outcome_chk');
});

it('classifies legal form from the Companies House prefix, leaving rows without a number unclassified', function () {
    $migration = complianceMigration('2026_10_14_090300_add_legal_form_to_applications_and_companies');
    $migration->down();

    $numbers = [
        '01234567' => 'limited_company', 'SC123456' => 'limited_company', 'ni123456' => 'limited_company',
        'OC123456' => 'llp', 'SO123456' => 'llp', 'NC123456' => 'llp',
        'SL123456' => 'partnership', 'LP123456' => 'partnership', 'NL123456' => 'partnership',
        'FC123456' => 'other', 'IP123456' => 'other', 'CE123456' => 'other',
    ];
    $applications = [];
    foreach (array_keys($numbers) as $number) {
        $applications[$number] = legacyApplication(['registration_number' => $number]);
    }
    $none = legacyApplication(['registration_number' => null]);
    $company = Company::factory()->create(['registration_number' => 'OC654321']);
    $companyWithout = Company::factory()->create(['registration_number' => null]);

    $migration->up();

    foreach ($numbers as $number => $form) {
        expect(DB::table('b2b_applications')->where('id', $applications[$number])->value('legal_form'))->toBe($form);
    }
    expect(DB::table('b2b_applications')->where('id', $none)->value('legal_form'))->toBeNull()
        ->and(DB::table('companies')->where('id', $company->id)->value('legal_form'))->toBe('llp')
        ->and(DB::table('companies')->where('id', $companyWithout->id)->value('legal_form'))->toBeNull();
});

it('requires a Companies House number for a limited company or LLP, and a known legal form', function () {
    expect(fn () => DB::transaction(fn () => legacyApplication(['legal_form' => 'limited_company', 'registration_number' => null])))
        ->toThrow(QueryException::class, 'b2b_applications_legal_form_number_chk');
    expect(fn () => DB::transaction(fn () => legacyApplication(['legal_form' => 'trust'])))
        ->toThrow(QueryException::class, 'b2b_applications_legal_form_chk');

    expect(legacyApplication(['legal_form' => 'sole_trader', 'registration_number' => null]))->toBeInt();
});

it('creates the check tables empty with their coherence rules, and seeds the stale-evidence setting', function () {
    $application = legacyApplication([]);

    expect(DB::table('vat_number_checks')->count())->toBe(0)
        ->and(DB::table('companies_house_checks')->count())->toBe(0)
        ->and(DB::table('system_configurations')->where('config_key', 'applications.verification_max_age_days')->value('value_int'))->toBe(30);

    // An XI number can only be checked through VIES (02 §25.4).
    expect(fn () => DB::transaction(fn () => DB::table('vat_number_checks')->insert([
        'b2b_application_id' => $application, 'vat_number' => 'XI123456789', 'authority' => 'hmrc',
        'outcome' => 'unchecked', 'failure_reason' => 'timeout',
    ])))->toThrow(QueryException::class, 'vat_number_checks_authority_prefix_chk');
    expect(fn () => DB::transaction(fn () => DB::table('companies_house_checks')->insert([
        'b2b_application_id' => $application, 'company_number' => '01234567', 'outcome' => 'unchecked',
    ])))->toThrow(QueryException::class, 'companies_house_checks_coherence_chk');
});

it('keeps each new backed enum in step with its CHECK constraint (02 §2.5)', function (string $constraint, string $enum) {
    $definition = (string) DB::table('pg_constraint')->where('conname', $constraint)
        ->selectRaw('pg_get_constraintdef(oid) AS def')->value('def');
    preg_match_all("/'([a-z_]+)'::text/", $definition, $matches);

    $values = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());
    sort($values);
    $allowed = array_values(array_unique($matches[1]));
    sort($allowed);

    expect($definition)->not->toBe('')
        ->and($allowed)->toBe($values);
})->with([
    'terms kind' => ['terms_versions_kind_chk', TermsKind::class],
    'acceptance source' => ['terms_acceptances_source_chk', TermsAcceptanceSource::class],
    'rejection category' => ['b2b_applications_rejection_category_chk', RejectionCategory::class],
    'legal form (applications)' => ['b2b_applications_legal_form_chk', LegalForm::class],
    'legal form (companies)' => ['companies_legal_form_chk', LegalForm::class],
]);
