<?php

use App\Domain\Identity\EmailVerificationLink;
use App\Domain\Identity\Totp;
use App\Models\B2bApplication;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserTwoFactorRecoveryCode;
use App\Notifications\Auth\ApplicationReceived;
use App\Notifications\Auth\ExistingAccount;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * 05.13 §5 (registration), §5.4 (breached passwords), §10 (reset), §11
 * (verification), §12.2 (2FA enrolment and recovery codes).
 */
const RECOVERY_PASSWORD = 'a-long-enough-passphrase';

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
    $this->breachedDir = storage_path('framework/testing/breached-'.uniqid());
    File::ensureDirectoryExists($this->breachedDir);
    config(['auth.breached_passwords.path' => $this->breachedDir]);
});

afterEach(function () {
    File::deleteDirectory($this->breachedDir);
});

/** Puts `$password` on the offline breached list, as HIBP's range file would. */
function markBreached(string $dir, string $password): void
{
    $sha1 = strtoupper(sha1($password));
    File::append($dir.'/'.substr($sha1, 0, 5).'.txt', substr($sha1, 5).":42\r\n");
}

/** @return array<string, mixed> */
function tradeForm(array $overrides = []): array
{
    return array_replace_recursive([
        'first_name' => 'Asha',
        'last_name' => 'Patel',
        'email' => 'asha@cornershop.example',
        'phone' => '020 7946 0000',
        'password' => RECOVERY_PASSWORD,
        'password_confirmation' => RECOVERY_PASSWORD,
        'company_name' => 'Corner Shop Ltd',
        'registration_number' => 'ab123456',
        'vat_number' => 'gb 980 780 684',
        'business_type' => 'convenience',
        'estimated_monthly_spend' => 2500,
        'address' => ['line1' => '1 High Street', 'city' => 'London', 'postcode' => 'e1 6an'],
        'terms' => '1',
    ], $overrides);
}

it('registers a public customer, unverified and not signed in', function () {
    $this->post('/register/public', [
        'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@example.com',
        'password' => RECOVERY_PASSWORD, 'password_confirmation' => RECOVERY_PASSWORD, 'terms' => '1',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    $user = User::query()->where('email', 'sam@example.com')->sole();
    expect($user->status)->toBe('active')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::info((string) $user->password_hash)['algoName'])->toBe('argon2id')
        ->and($user->companies()->exists())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
    $this->assertGuest();
});

it('files a trade application with the account, in one step', function () {
    $this->post('/register/trade', tradeForm())->assertRedirect(route('login'));

    $user = User::query()->where('email', 'asha@cornershop.example')->sole();
    $application = B2bApplication::query()->sole();

    expect($application->applicant_user_id)->toBe($user->id)
        ->and($application->status)->toBe('submitted')
        ->and($application->vat_number)->toBe('GB980780684')
        ->and($application->registration_number)->toBe('AB123456')
        ->and($application->estimated_monthly_spend_minor)->toBe(250000)
        ->and($application->address['postcode'])->toBe('E1 6AN')
        ->and($application->contact_name)->toBe('Asha Patel');

    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertSentTo($user, ApplicationReceived::class);
});

it('rejects an invalid VAT number and company number', function () {
    $this->post('/register/trade', tradeForm(['vat_number' => 'GB123456789', 'registration_number' => '1234']))
        ->assertSessionHasErrors(['vat_number', 'registration_number']);

    expect(User::query()->count())->toBe(0);
});

it('answers an already-registered email exactly like a new one, and emails the owner instead', function () {
    $existing = User::factory()->create(['email' => 'asha@cornershop.example']);

    $this->post('/register/trade', tradeForm())->assertRedirect(route('login'))->assertSessionHas('status');

    expect(User::query()->count())->toBe(1)
        ->and(B2bApplication::query()->count())->toBe(0);
    Notification::assertSentTo($existing, ExistingAccount::class);
    Notification::assertNotSentTo($existing, VerifyEmail::class);
});

it('refuses a password on the offline breached list, and one under 12 characters', function () {
    markBreached($this->breachedDir, 'correct horse battery staple');

    $this->post('/register/trade', tradeForm(['password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple']))
        ->assertSessionHasErrors('password');
    $this->post('/register/trade', tradeForm(['password' => 'short-pass', 'password_confirmation' => 'short-pass']))
        ->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('refuses an email containing a line break', function () {
    $this->post('/register/trade', tradeForm(['email' => "asha@cornershop.example\r\nBcc: x@example.com"]))
        ->assertSessionHasErrors('email');
});

it('verifies an email from its link, and refuses a tampered or expired one', function () {
    $user = User::factory()->unverified()->create();

    $url = EmailVerificationLink::url($user);
    $this->get(str_replace(substr($url, -8), '00000000', $url))->assertRedirect(route('login'));
    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->travel(25)->hours();
    $this->get($url)->assertRedirect(route('login'));
    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->travelBack();
    $this->get(EmailVerificationLink::url($user))->assertRedirect(route('login'));
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->assertGuest();
});

it('stops a verification link working once the address changes', function () {
    $user = User::factory()->unverified()->create();
    $url = EmailVerificationLink::url($user);

    $user->forceFill(['email' => 'new-address@example.com'])->save();
    $this->get($url);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('sends a reset link only for a real account, answering both the same way', function () {
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

it('resets the password once per link and signs in on this device', function () {
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $new = 'a-brand-new-passphrase';
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])
        ->assertRedirect(route('order-pad'));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check($new, (string) $user->fresh()->password_hash))->toBeTrue();

    $this->post('/logout');
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'yet-another-passphrase', 'password_confirmation' => 'yet-another-passphrase'])
        ->assertSessionHasErrors('email');
});

it('never skips the second factor after a reset', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->post('/forgot-password', ['email' => $user->email]);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
});

it('activates a pending staff user who sets their first password (§5.3)', function () {
    $user = User::factory()->pending()->create(['password_hash' => null]);
    $this->post('/forgot-password', ['email' => $user->email]);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase']);

    expect($user->fresh()->status)->toBe('active')
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('enrols in 2FA in three steps, switching it on only once the codes are saved', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $secret = $this->get('/two-factor/setup')->assertOk()->viewData('page')['props']['secret'];
    $code = Totp::codeAt($secret, intdiv(now()->getTimestamp(), Totp::PERIOD));

    $this->post('/two-factor/setup/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/two-factor/setup/confirm', ['code' => $code])->assertRedirect(route('two-factor.setup'));

    $codes = $this->get('/two-factor/setup')->viewData('page')['props']['pending_codes'];
    expect($codes)->toHaveCount(8)
        ->and($user->fresh()->two_factor_enabled)->toBeFalse();

    $this->post('/two-factor/setup/complete', [])->assertSessionHasErrors('saved');
    $this->post('/two-factor/setup/complete', ['saved' => '1'])->assertRedirect();

    $fresh = $user->fresh();
    expect($fresh->two_factor_enabled)->toBeTrue()
        ->and($fresh->two_factor_secret)->toBe($secret)
        ->and(UserTwoFactorRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(8)
        ->and(UserTwoFactorRecoveryCode::query()->pluck('code_hash')->intersect($codes)->all())->toBe([]);
});

it('stores the TOTP secret encrypted, never in plain text', function () {
    $user = User::factory()->withTwoFactor()->create();

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    $raw = is_resource($raw) ? stream_get_contents($raw) : $raw;

    expect($raw)->not->toContain($user->two_factor_secret);
});

it('lets a trade user turn 2FA off with their password, but never staff', function () {
    $trade = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(RECOVERY_PASSWORD)]);
    $this->actingAs($trade)->delete('/two-factor', ['password' => 'wrong-password-here'])->assertSessionHasErrors('password');
    $this->actingAs($trade)->delete('/two-factor', ['password' => RECOVERY_PASSWORD])->assertRedirect();
    expect($trade->fresh()->two_factor_enabled)->toBeFalse();

    $staff = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(RECOVERY_PASSWORD)]);
    RoleUser::create(['role_id' => Role::factory()->create(['code' => 'accounts'])->id, 'user_id' => $staff->id]);
    $this->actingAs($staff)->delete('/two-factor', ['password' => RECOVERY_PASSWORD])->assertForbidden();
    expect($staff->fresh()->two_factor_enabled)->toBeTrue();
});
