<?php

use App\Domain\Accounts\BusinessVerification;
use App\Domain\Accounts\VatCheckOutcome;
use App\Domain\Accounts\Verification\HmrcVatClient;
use App\Jobs\VerifyApplicationBusiness;
use App\Models\B2bApplication;
use App\Models\CompaniesHouseCheck;
use App\Models\SystemConfiguration;
use App\Models\TermsVersion;
use App\Models\User;
use App\Models\VatNumberCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 02 §25.4–25.5: the HMRC, VIES and Companies House checks, and the job
 * that runs them. Every HTTP call is faked; a stray one fails the test.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.hmrc_vat' => ['base_url' => 'https://hmrc.test', 'client_id' => 'client', 'client_secret' => 'secret'],
        'services.vies.base_url' => 'https://vies.test',
        'services.companies_house' => ['base_url' => 'https://ch.test', 'key' => 'ch-key'],
    ]);
});

/** @param array<string, mixed> $overrides */
function verifiedApplication(array $overrides = []): B2bApplication
{
    return B2bApplication::factory()->create([
        'company_name' => 'Quay Stores Ltd',
        'legal_form' => 'limited_company',
        'registration_number' => '01234567',
        'vat_number' => 'GB980780684',
        ...$overrides,
    ]);
}

function hmrcValid(): array
{
    return [
        'target' => ['name' => 'QUAY STORES LTD', 'vatNumber' => '980780684', 'address' => ['line1' => '1 Quay Street', 'postcode' => 'BS1 4DJ', 'countryCode' => 'GB']],
        'processingDate' => '2026-09-28T10:00:00+01:00',
        'consultationNumber' => 'KFB-J8S-7XQ',
    ];
}

function companiesHouseActive(string $type = 'ltd', string $status = 'active'): array
{
    return [
        'company_name' => 'QUAY STORES LIMITED',
        'company_status' => $status,
        'type' => $type,
        'registered_office_address' => ['address_line_1' => '1 Quay Street', 'locality' => 'Bristol', 'postal_code' => 'BS1 4DJ'],
        'date_of_creation' => '2019-03-14',
    ];
}

function runChecks(B2bApplication $application, bool $final = true): bool
{
    return app(BusinessVerification::class)->run($application, null, $final, now()->subSecond());
}

it('records HMRC and Companies House evidence, quoting our VRN for a consultation number', function () {
    SystemConfiguration::factory()->create(['config_key' => 'seller.vat_number', 'value_type' => 'text', 'value_int' => null, 'value_text' => 'GB 123 4567 89']);
    Http::fake([
        'hmrc.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 14400]),
        'hmrc.test/organisations/*' => Http::response(hmrcValid()),
        'ch.test/company/01234567' => Http::response(companiesHouseActive()),
    ]);
    $application = verifiedApplication();

    expect(runChecks($application))->toBeFalse();

    $vat = VatNumberCheck::query()->sole();
    expect($vat->authority)->toBe('hmrc')
        ->and($vat->outcome)->toBe('valid')
        ->and($vat->vat_number)->toBe('GB980780684')
        ->and($vat->registered_name)->toBe('QUAY STORES LTD')
        ->and($vat->registered_address)->toBe(hmrcValid()['target']['address'])
        ->and($vat->consultation_number)->toBe('KFB-J8S-7XQ')
        ->and($vat->processed_at?->toIso8601String())->toBe('2026-09-28T09:00:00+00:00')
        ->and($vat->requested_by_user_id)->toBeNull();

    $company = CompaniesHouseCheck::query()->sole();
    expect($company->outcome)->toBe('found')
        ->and($company->company_status)->toBe('active')
        ->and($company->company_type)->toBe('ltd')
        ->and($company->registered_name)->toBe('QUAY STORES LIMITED')
        ->and($company->incorporated_on?->toDateString())->toBe('2019-03-14');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://hmrc.test/organisations/vat/check-vat-number/lookup/980780684/123456789'
        && $request->hasHeader('Authorization', 'Bearer tok')
        && $request->hasHeader('Accept', 'application/vnd.hmrc.2.0+json'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://ch.test/company/01234567'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ch-key:')));
});

it('records not found and the failure reasons HMRC and Companies House give', function (int $status, string $vatOutcome, ?string $vatReason, string $chOutcome, ?string $chReason) {
    Http::fake([
        'hmrc.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 14400]),
        'hmrc.test/organisations/*' => Http::response(['code' => 'X'], $status),
        'ch.test/*' => Http::response(['errors' => []], $status),
    ]);
    $application = verifiedApplication();

    runChecks($application);

    $vat = VatNumberCheck::query()->sole();
    $company = CompaniesHouseCheck::query()->sole();
    expect([$vat->outcome, $vat->failure_reason, $company->outcome, $company->failure_reason])->toBe([$vatOutcome, $vatReason, $chOutcome, $chReason]);
})->with([
    'not found' => [404, 'not_found', null, 'not_found', null],
    'rate limited' => [429, 'unchecked', 'rate_limited', 'unchecked', 'rate_limited'],
    'down' => [503, 'unchecked', 'unavailable', 'unchecked', 'unavailable'],
    'gateway timeout' => [504, 'unchecked', 'timeout', 'unchecked', 'timeout'],
    'odd answer' => [418, 'unchecked', 'unexpected_response', 'unchecked', 'unexpected_response'],
]);

it('records an unreachable service as a timeout', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds'));
    runChecks($timedOut = verifiedApplication());

    expect(VatNumberCheck::query()->where('b2b_application_id', $timedOut->id)->value('failure_reason'))->toBe('timeout')
        ->and(CompaniesHouseCheck::query()->where('b2b_application_id', $timedOut->id)->value('failure_reason'))->toBe('timeout');
});

it('records a missing configuration without calling anyone', function () {
    config(['services.hmrc_vat.client_id' => null, 'services.companies_house.key' => '']);
    Http::fake();
    runChecks($unconfigured = verifiedApplication());
    Http::assertNothingSent();
    expect(VatNumberCheck::query()->where('b2b_application_id', $unconfigured->id)->first()?->only(['outcome', 'failure_reason']))
        ->toBe(['outcome' => 'unchecked', 'failure_reason' => 'not_configured'])
        ->and(CompaniesHouseCheck::query()->where('b2b_application_id', $unconfigured->id)->value('failure_reason'))->toBe('not_configured');
});

it('checks XI numbers through VIES, storing withheld details as NULL', function () {
    Http::fake(['vies.test/check-vat-number' => Http::response([
        'countryCode' => 'XI', 'vatNumber' => '980780684', 'requestDate' => '2026-09-28T10:00:00.000Z',
        'valid' => true, 'requestIdentifier' => 'WAPIAAAAY', 'name' => '---', 'address' => "1 QUAY STREET\nBELFAST BT1 1AA",
    ])]);
    $application = verifiedApplication(['legal_form' => 'sole_trader', 'vat_number' => 'XI980780684', 'registration_number' => null]);

    runChecks($application);

    $vat = VatNumberCheck::query()->sole();
    expect($vat->authority)->toBe('vies')
        ->and($vat->outcome)->toBe('valid')
        ->and($vat->registered_name)->toBeNull()
        ->and($vat->registered_address)->toBe(['text' => "1 QUAY STREET\nBELFAST BT1 1AA"])
        ->and($vat->consultation_number)->toBe('WAPIAAAAY');
    Http::assertSent(fn (Request $request) => $request['countryCode'] === 'XI' && $request['vatNumber'] === '980780684' && ! isset($request['requesterNumber']));
});

it('maps VIES member-state outages and invalid numbers', function (array $body, string $outcome, ?string $reason) {
    Http::fake(['vies.test/*' => Http::response($body)]);
    runChecks(verifiedApplication(['legal_form' => 'sole_trader', 'vat_number' => 'XI980780684', 'registration_number' => null]));

    expect(VatNumberCheck::query()->sole()->only(['outcome', 'failure_reason']))->toBe(['outcome' => $outcome, 'failure_reason' => $reason]);
})->with([
    'invalid' => [['valid' => false, 'requestDate' => '2026-09-28T10:00:00.000Z', 'userError' => 'INVALID'], 'not_found', null],
    'member state down' => [['userError' => 'MS_UNAVAILABLE'], 'unchecked', 'unavailable'],
    'busy' => [['errorWrappers' => [['error' => 'MS_MAX_CONCURRENT_REQ']]], 'unchecked', 'rate_limited'],
]);

it('holds a transient failure back for a retry, writing only the final result', function () {
    Http::fake([
        'hmrc.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 14400]),
        'hmrc.test/organisations/*' => Http::response(hmrcValid()),
        'ch.test/*' => Http::response([], 503),
    ]);
    $application = verifiedApplication();

    $first = (new VerifyApplicationBusiness($application->id))->withFakeQueueInteractions();
    $first->handle(app(BusinessVerification::class));
    $first->assertReleased(10);
    expect(VatNumberCheck::query()->count())->toBe(1)
        ->and(CompaniesHouseCheck::query()->count())->toBe(0);

    // The retry (the same job, as the queue re-runs it) skips the VAT check it
    // already wrote, and on its last attempt records the outage.
    $last = (clone $first)->withFakeQueueInteractions();
    $last->job->attempts = 4;
    $last->handle(app(BusinessVerification::class));
    $last->assertNotReleased();
    expect(VatNumberCheck::query()->count())->toBe(1)
        ->and(CompaniesHouseCheck::query()->sole()->failure_reason)->toBe('unavailable');
    Http::assertSentCount(4);
});

it('queues the checks after a trade registration, and never makes the applicant wait on them', function () {
    Queue::fake();
    $this->withoutVite();
    $publisher = User::factory()->create();
    $terms = TermsVersion::factory()->create(['published_by_user_id' => $publisher->id]);
    $form = fn (string $email, array $extra) => [
        'first_name' => 'Ravi', 'last_name' => 'Mistry', 'email' => $email, 'phone' => '0117 496 0000',
        'password' => 'a-long-enough-passphrase', 'password_confirmation' => 'a-long-enough-passphrase',
        'company_name' => 'Quay Stores Ltd', 'business_type' => 'convenience',
        'address' => ['line1' => '1 Quay Street', 'city' => 'Bristol', 'postcode' => 'BS1 4DJ'],
        'terms' => '1', 'terms_version_id' => $terms->id, ...$extra,
    ];
    config(['auth.breached_passwords.path' => $dir = storage_path('framework/testing/breached-'.uniqid())]);
    File::ensureDirectoryExists($dir);

    $this->post('/register/trade', $form('ltd@quaystores.example', ['legal_form' => 'limited_company', 'registration_number' => '01234567', 'vat_number' => 'GB980780684']))
        ->assertRedirect(route('login'));
    $filed = B2bApplication::query()->where('contact_email', 'ltd@quaystores.example')->sole();
    Queue::assertPushed(VerifyApplicationBusiness::class, fn (VerifyApplicationBusiness $job) => $job->applicationId === $filed->id && $job->requestedByUserId === null);

    $this->post('/register/trade', $form('sole@quaystores.example', ['legal_form' => 'sole_trader']))->assertRedirect(route('login'));
    Queue::assertPushed(VerifyApplicationBusiness::class, 1);

    File::deleteDirectory($dir);
});

it('stores HMRC processing dates in UTC using London only when no offset is supplied', function (string $processed, string $expected) {
    Http::fake([
        'hmrc.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 14400]),
        'hmrc.test/organisations/*' => Http::response([...hmrcValid(), 'processingDate' => $processed]),
    ]);
    runChecks(verifiedApplication(['legal_form' => 'sole_trader', 'registration_number' => null]));

    expect(VatNumberCheck::query()->sole()->processed_at?->toIso8601String())->toBe($expected);
})->with([
    'explicit summer offset' => ['2026-09-28T10:00:00+01:00', '2026-09-28T09:00:00+00:00'],
    'explicit UTC' => ['2026-09-28T10:00:00Z', '2026-09-28T10:00:00+00:00'],
    'different offset' => ['2026-09-28T10:00:00-04:00', '2026-09-28T14:00:00+00:00'],
    'implicit BST' => ['2026-09-28T10:00:00', '2026-09-28T09:00:00+00:00'],
    'implicit GMT' => ['2026-01-28T10:00:00', '2026-01-28T10:00:00+00:00'],
]);

it('discards a rejected HMRC token so the next lookup obtains a new one', function () {
    Cache::put('verification:hmrc:token', 'stale', 3600);
    Http::fake([
        'hmrc.test/oauth/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 14400]),
        'hmrc.test/organisations/*' => Http::sequence()->push([], 401)->push(hmrcValid()),
    ]);
    $client = app(HmrcVatClient::class);

    expect($client->lookup('GB980780684', null)->outcome)->toBe(VatCheckOutcome::Unchecked)
        ->and(Cache::has('verification:hmrc:token'))->toBeFalse();
    Http::assertSentCount(1);

    expect($client->lookup('GB980780684', null)->outcome)->toBe(VatCheckOutcome::Valid);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/lookup/') && $request->hasHeader('Authorization', 'Bearer fresh'));
});

it('makes registered legal forms with a registration number even when overridden with null', function (string $legalForm) {
    $application = B2bApplication::factory()->create(['legal_form' => $legalForm, 'registration_number' => null]);

    expect($application->fresh()?->registration_number)->toMatch('/^(?:[0-9]{8}|OC[0-9]{6})$/');
    expect(B2bApplication::factory()->make(['legal_form' => $legalForm, 'registration_number' => 'SC123456'])->registration_number)->toBe('SC123456');
})->with(['limited_company', 'llp']);
