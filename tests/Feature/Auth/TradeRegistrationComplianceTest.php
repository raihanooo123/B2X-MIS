<?php

use App\Domain\Accounts\AcceptedTerms;
use App\Domain\Accounts\LegalForm;
use App\Domain\Delivery\Postcode;
use App\Domain\Identity\BusinessType;
use App\Domain\Identity\Registration;
use App\Domain\Notifications\Notices\ApplicationReapplyBlocked;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->breachedDir = storage_path('framework/testing/breached-'.uniqid());
    File::ensureDirectoryExists($this->breachedDir);
    config(['auth.breached_passwords.path' => $this->breachedDir]);
    // Excluded from the registrant counts below.
    $this->termsPublisher = User::factory()->create();
    $this->terms = TermsVersion::factory()->create(['version' => 'v1', 'published_by_user_id' => $this->termsPublisher->id]);
});

afterEach(function () {
    File::deleteDirectory($this->breachedDir);
});

/** @return array<string, mixed> */
function complianceForm(TermsVersion $terms, array $overrides = []): array
{
    return array_replace_recursive([
        'first_name' => 'Ravi',
        'last_name' => 'Mistry',
        'email' => 'ravi@quaystores.example',
        'phone' => '0117 496 0000',
        'password' => 'a-long-enough-passphrase',
        'password_confirmation' => 'a-long-enough-passphrase',
        'company_name' => 'Quay Stores Ltd',
        'legal_form' => 'limited_company',
        'registration_number' => '01234567',
        'business_type' => 'convenience',
        'address' => ['line1' => '1 Quay Street', 'city' => 'Bristol', 'postcode' => 'bs14dj'],
        'terms' => '1',
        'terms_version_id' => $terms->id,
    ], $overrides);
}

/** @return array{company_name: string, legal_form: LegalForm, registration_number: ?string, vat_number: ?string, business_type: BusinessType, estimated_monthly_spend_minor: ?int, address: array<string, string|null>} */
function filedApplication(): array
{
    return [
        'company_name' => 'Second Go Ltd',
        'legal_form' => LegalForm::SoleTrader,
        'registration_number' => null,
        'vat_number' => null,
        'business_type' => BusinessType::Convenience,
        'estimated_monthly_spend_minor' => null,
        'address' => ['line1' => '2 Quay Street', 'line2' => null, 'city' => 'Bristol', 'county' => null, 'postcode' => 'BS1 4DJ', 'country_code' => 'GB'],
    ];
}

it('shows the terms in force and records their acceptance with the client IP through a trusted proxy', function () {
    config(['trustedproxy.proxies' => '10.0.0.1']);

    $this->get('/register')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/Register', false)
        ->where('trade_terms.id', $this->terms->id)
        ->where('trade_terms.version', 'v1')
        ->has('legal_forms', 5));

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'User-Agent' => 'TradeBrowser/1.0'])
        ->post('/register/trade', complianceForm($this->terms))
        ->assertRedirect(route('login'));

    $user = User::query()->where('email', 'ravi@quaystores.example')->sole();
    $application = B2bApplication::query()->sole();
    $acceptance = TermsAcceptance::query()->sole();

    expect($application->legal_form)->toBe('limited_company')
        ->and($application->address['postcode'])->toBe('BS1 4DJ')
        ->and($acceptance->terms_version_id)->toBe($this->terms->id)
        ->and($acceptance->user_id)->toBe($user->id)
        ->and($acceptance->b2b_application_id)->toBe($application->id)
        ->and($acceptance->source)->toBe('trade_application')
        ->and($acceptance->ip)->toBe('203.0.113.9')
        ->and($acceptance->user_agent)->toBe('TradeBrowser/1.0')
        ->and($acceptance->accepted_at)->not->toBeNull();
});

it('ignores forwarded addresses from an untrusted caller', function () {
    config(['trustedproxy.proxies' => []]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->post('/register/trade', complianceForm($this->terms))
        ->assertRedirect(route('login'));

    expect(TermsAcceptance::query()->sole()->ip)->toBe('198.51.100.7');
});

it('asks the applicant to read new terms that took effect while the form was open', function () {
    $newer = TermsVersion::factory()->create(['version' => 'v2', 'effective_from' => now()->subSecond(), 'published_by_user_id' => $this->termsPublisher->id]);

    $this->from('/register')->post('/register/trade', complianceForm($this->terms))
        ->assertRedirect('/register')
        ->assertSessionHasErrors(['terms_version_id' => Registration::TERMS_CHANGED]);

    expect(User::query()->whereKeyNot($this->termsPublisher->id)->count())->toBe(0)
        ->and(B2bApplication::query()->count())->toBe(0)
        ->and(TermsAcceptance::query()->count())->toBe(0);

    $this->get('/register')->assertInertia(fn (AssertableInertia $page) => $page->where('trade_terms.id', $newer->id));

    $this->post('/register/trade', complianceForm($newer))->assertRedirect(route('login'));
    expect(TermsAcceptance::query()->sole()->terms_version_id)->toBe($newer->id);
});

it('refuses a filing whose terms changed inside the transaction, creating neither the user nor the application', function () {
    TermsVersion::factory()->create(['version' => 'v2', 'effective_from' => now()->subSecond(), 'published_by_user_id' => $this->termsPublisher->id]);
    $form = complianceForm($this->terms);

    expect(fn () => Registration::tradeApplicant(
        ['first_name' => 'Ravi', 'last_name' => 'Mistry', 'email' => 'ravi@quaystores.example', 'password' => $form['password'], 'phone' => $form['phone']],
        filedApplication(),
        new AcceptedTerms($this->terms->id, '203.0.113.9', null),
    ))->toThrow(ValidationException::class);

    expect(User::query()->whereKeyNot($this->termsPublisher->id)->count())->toBe(0)
        ->and(B2bApplication::query()->count())->toBe(0);
});

it('refuses trade registration when no terms of trade are in force', function () {
    // Before v1 took effect, nothing is in force (versions can never be deleted).
    $this->travelTo($this->terms->effective_from->copy()->subHour());

    $this->get('/register')->assertInertia(fn (AssertableInertia $page) => $page->where('trade_terms', null));

    $this->post('/register/trade', complianceForm($this->terms))->assertSessionHasErrors(['terms_version_id']);
    expect(User::query()->whereKeyNot($this->termsPublisher->id)->count())->toBe(0);
});

it('requires a legal form, and a Companies House number only for a limited company or LLP', function () {
    $this->post('/register/trade', complianceForm($this->terms, ['legal_form' => '']))->assertSessionHasErrors(['legal_form']);
    $this->post('/register/trade', complianceForm($this->terms, ['legal_form' => 'trust']))->assertSessionHasErrors(['legal_form']);
    $this->post('/register/trade', complianceForm($this->terms, ['legal_form' => 'limited_company', 'registration_number' => '']))->assertSessionHasErrors(['registration_number']);
    $this->post('/register/trade', complianceForm($this->terms, ['legal_form' => 'llp', 'registration_number' => '']))->assertSessionHasErrors(['registration_number']);
    expect(User::query()->whereKeyNot($this->termsPublisher->id)->count())->toBe(0);

    $this->post('/register/trade', complianceForm($this->terms, ['legal_form' => 'sole_trader', 'registration_number' => '']))->assertRedirect(route('login'));
    expect(B2bApplication::query()->sole()->legal_form)->toBe('sole_trader')
        ->and(B2bApplication::query()->sole()->registration_number)->toBeNull();
});

it('formats UK postcodes in the standard way and leaves other text alone', function () {
    expect(Postcode::format('sw1a1aa'))->toBe('SW1A 1AA')
        ->and(Postcode::format(' SW1A   1AA '))->toBe('SW1A 1AA')
        ->and(Postcode::format('e16an'))->toBe('E1 6AN')
        ->and(Postcode::format('M11AE'))->toBe('M1 1AE')
        ->and(Postcode::format('ec1a 1bb'))->toBe('EC1A 1BB')
        ->and(Postcode::format('not a postcode'))->toBe('NOT A POSTCODE');
});

it('holds a new application until reapply_after unless the last rejection was remediable', function () {
    $user = User::factory()->create();
    $accepted = fn () => new AcceptedTerms($this->terms->id, null, null);
    B2bApplication::factory()->rejected(now()->addDays(30))->create([
        'applicant_user_id' => $user->id, 'contact_email' => $user->email, 'reviewed_at' => now()->subDays(60),
    ]);

    expect(fn () => Registration::fileApplication($user, null, filedApplication(), $accepted()))
        ->toThrow(ValidationException::class, 'You can apply for a trade account again from '.now()->addDays(30)->timezone('Europe/London')->format('j F Y'));
    expect(B2bApplication::query()->where('status', 'submitted')->exists())->toBeFalse()
        ->and(TermsAcceptance::query()->exists())->toBeFalse();

    $this->travel(31)->days();
    $filed = Registration::fileApplication($user, null, filedApplication(), $accepted());
    expect($filed->status)->toBe('submitted')
        ->and(TermsAcceptance::query()->sole()->b2b_application_id)->toBe($filed->id);

    // One open application per user.
    expect(fn () => Registration::fileApplication($user, null, filedApplication(), $accepted()))
        ->toThrow(ValidationException::class, 'already have an application in progress');
});

it('goes by the latest rejection: a later remediable one lifts an earlier cooling period', function () {
    $user = User::factory()->create();
    B2bApplication::factory()->rejected(now()->addDays(60))->create([
        'applicant_user_id' => $user->id, 'contact_email' => $user->email, 'reviewed_at' => now()->subDays(30),
    ]);
    B2bApplication::factory()->rejected()->create([
        'applicant_user_id' => $user->id, 'contact_email' => $user->email, 'reviewed_at' => now()->subDay(),
    ]);

    expect(Registration::fileApplication($user, null, filedApplication(), new AcceptedTerms($this->terms->id, null, null))->status)->toBe('submitted');
});

it('answers a cooling rejection or an open application on the public form exactly like every other outcome, emailing only that address', function () {
    User::factory()->create(['email' => 'existing@quaystores.example']);
    B2bApplication::factory()->rejected(now()->addDays(10))->create(['applicant_user_id' => null, 'contact_email' => 'cooling@quaystores.example']);
    B2bApplication::factory()->rejected()->create(['applicant_user_id' => null, 'contact_email' => 'remediable@quaystores.example']);
    $open = B2bApplication::factory()->create(['applicant_user_id' => null, 'contact_email' => 'open@quaystores.example', 'status' => 'in_review']);

    $responses = [];
    foreach (['new', 'existing', 'cooling', 'remediable', 'open'] as $outcome) {
        $this->flushSession();
        $response = $this->post('/register/trade', complianceForm($this->terms, ['email' => "{$outcome}@quaystores.example"]));
        $responses[$outcome] = [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'flash' => session('status'),
            'errors' => session('errors')?->getBag('default')->toArray() ?? [],
        ];
    }

    expect($responses['cooling'])->toBe($responses['new'])
        ->and($responses['existing'])->toBe($responses['new'])
        ->and($responses['remediable'])->toBe($responses['new'])
        ->and($responses['open'])->toBe($responses['new'])
        ->and($responses['new']['location'])->toBe(route('login'))
        ->and($responses['new']['errors'])->toBe([]);

    // The cooling address gets no account and no application — only the email.
    expect(User::query()->where('email', 'cooling@quaystores.example')->exists())->toBeFalse()
        ->and(B2bApplication::query()->where('contact_email', 'cooling@quaystores.example')->where('status', 'submitted')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'remediable@quaystores.example')->exists())->toBeTrue()
        ->and(B2bApplication::query()->where('contact_email', 'remediable@quaystores.example')->where('status', 'submitted')->exists())->toBeTrue();

    $sent = DB::table('notification_log')->where('notification_key', NotificationKey::ApplicationReapplyBlocked->value)->get();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->recipient)->toBe('cooling@quaystores.example')
        ->and($sent[0]->user_id)->toBeNull();

    // The open address: nothing new filed, no account, and only that address told why.
    expect(User::query()->where('email', 'open@quaystores.example')->exists())->toBeFalse()
        ->and(B2bApplication::query()->where('contact_email', 'open@quaystores.example')->pluck('id')->all())->toBe([$open->id])
        ->and(TermsAcceptance::query()->where('b2b_application_id', $open->id)->exists())->toBeFalse();
    $told = DB::table('notification_log')->where('notification_key', NotificationKey::ApplicationAlreadyOpen->value)->get();
    expect($told)->toHaveCount(1)
        ->and($told[0]->recipient)->toBe('open@quaystores.example')
        ->and($told[0]->user_id)->toBeNull()
        ->and((int) $told[0]->subject_id)->toBe($open->id)
        ->and(DB::table('notification_log')->where('recipient', 'open@quaystores.example')->count())->toBe(1);

    $cooling = B2bApplication::query()->where('contact_email', 'cooling@quaystores.example')->sole();
    $mail = (new ApplicationReapplyBlocked($cooling->id))->content(new Recipient('cooling@quaystores.example'));
    expect(implode(' ', $mail->paragraphs))->toContain('can be made from '.$cooling->reapply_after->timezone('Europe/London')->format('j F Y'));

    // Once the cooling period ends, the same address applies normally.
    $this->travel(11)->days();
    $this->post('/register/trade', complianceForm($this->terms, ['email' => 'cooling@quaystores.example']))->assertRedirect(route('login'));
    expect(User::query()->where('email', 'cooling@quaystores.example')->exists())->toBeTrue();
});

it('trusts no proxy unless TRUSTED_PROXIES lists one, and never defaults to every caller', function () {
    $load = function (?string $value): array {
        $previous = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null];
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
        try {
            return require config_path('trustedproxy.php');
        } finally {
            [$_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']] = $previous;
        }
    };

    expect($load(''))->toBe(['proxies' => []])
        ->and($load(null))->toBe(['proxies' => []])
        ->and($load(' 10.0.0.1 , 10.1.0.0/16 '))->toBe(['proxies' => ['10.0.0.1', '10.1.0.0/16']]);
});
