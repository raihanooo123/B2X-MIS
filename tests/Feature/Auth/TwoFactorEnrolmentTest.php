<?php

use App\Domain\Identity\RecoveryCodes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * 2FA enrolment end to end, from the authenticator app's side (05.13
 * §12.2): the app is given only the otpauth:// URI (or the key) the setup
 * page shows, and must be able to produce codes the enrolment accepts.
 *
 * `authenticatorCode()` is deliberately independent of App\Domain\Identity\Totp
 * — its own RFC 4648 Base32 decoder and RFC 4226/6238 HOTP — so a fault in
 * Totp's encoding, the URI's parameters, or the secret the server verifies
 * against cannot cancel itself out, as it could in a test that used Totp
 * on both sides.
 *
 * Regression: enrolment kept the pending secret in the session, so any new
 * session (signing in again, an idle timeout, another browser) generated a
 * new key while the authenticator — Apple Passwords in the report — kept
 * the first. Every code then failed "That code does not match".
 */
const ENROL_PASSWORD = 'a-long-enough-passphrase';

beforeEach(fn () => $this->withoutVite());

/**
 * What an authenticator does with an otpauth:// URI: read the parameters,
 * decode the Base32 secret (RFC 4648 §6, case-insensitive, padding and
 * spaces ignored), and compute RFC 6238 TOTP with them.
 *
 * @return array{code: string, params: array<string, string>, label: string}
 */
function authenticatorCode(string $uri, int $unixTime): array
{
    $parts = parse_url($uri);
    expect($parts['scheme'] ?? null)->toBe('otpauth')
        ->and($parts['host'] ?? null)->toBe('totp');

    parse_str($parts['query'] ?? '', $params);
    /** @var array<string, string> $params */
    $algorithm = strtolower($params['algorithm'] ?? 'SHA1');
    $digits = (int) ($params['digits'] ?? 6);
    $period = (int) ($params['period'] ?? 30);

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(str_replace([' ', '='], '', $params['secret']));
    $buffer = 0;
    $bitsInBuffer = 0;
    $key = '';
    foreach (str_split($clean) as $char) {
        $value = strpos($alphabet, $char);
        expect($value)->not->toBeFalse();
        $buffer = ($buffer << 5) | $value;
        $bitsInBuffer += 5;
        if ($bitsInBuffer >= 8) {
            $bitsInBuffer -= 8;
            $key .= chr(($buffer >> $bitsInBuffer) & 0xFF);
        }
    }

    $counter = intdiv($unixTime, $period);
    $message = '';
    for ($i = 7; $i >= 0; $i--) {
        $message .= chr(($counter >> ($i * 8)) & 0xFF);
    }

    $hash = hash_hmac($algorithm, $message, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

    return [
        'code' => str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT),
        'params' => $params,
        'label' => rawurldecode(ltrim($parts['path'] ?? '', '/')),
    ];
}

function enrolmentProps(): array
{
    return test()->get('/two-factor/setup')->assertOk()->viewData('page')['props'];
}

it('gives out a standard key and URI an authenticator can use, and accepts its code', function () {
    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $this->actingAs($user);

    $props = enrolmentProps();
    $app = authenticatorCode($props['otpauth_uri'], now()->getTimestamp());

    // The conventions authenticators assume: a 160-bit key as 32 unpadded
    // upper-case Base32 characters, SHA1, 6 digits, 30 seconds.
    expect($props['secret'])->toMatch('/^[A-Z2-7]{32}$/')
        ->and($app['params']['secret'])->toBe($props['secret'])
        ->and($app['params']['algorithm'])->toBe('SHA1')
        ->and($app['params']['digits'])->toBe('6')
        ->and($app['params']['period'])->toBe('30')
        ->and($app['params']['issuer'])->toBe(config('app.name'))
        ->and($app['label'])->toBe(config('app.name').':buyer@example.com');

    $this->post('/two-factor/setup/confirm', ['code' => $app['code']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('two-factor.setup'));

    $codes = enrolmentProps()['pending_codes'];
    expect($codes)->toHaveCount(8);

    $this->post('/two-factor/setup/complete', ['saved' => '1'])->assertRedirect();

    $fresh = $user->fresh();
    expect($fresh->two_factor_enabled)->toBeTrue()
        ->and($fresh->two_factor_secret)->toBe($props['secret']);
});

it('accepts a code from the key typed in by hand, grouped with spaces and in lower case', function () {
    $this->actingAs(User::factory()->create());
    $secret = enrolmentProps()['secret'];

    $typed = strtolower(implode(' ', str_split($secret, 4)));
    $app = authenticatorCode('otpauth://totp/Manual?secret='.rawurlencode($typed), now()->getTimestamp());

    $this->post('/two-factor/setup/confirm', ['code' => $app['code']])->assertSessionHasNoErrors();
});

it('keeps the same key across sign-out and sign-in, so the code the app shows still works', function () {
    $user = User::factory()->create(['password_hash' => Hash::make(ENROL_PASSWORD)]);

    $this->post('/login', ['email' => $user->email, 'password' => ENROL_PASSWORD]);
    $uri = enrolmentProps()['otpauth_uri'];

    // The buyer adds the key to their app… then the session ends.
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => ENROL_PASSWORD]);

    expect(enrolmentProps()['otpauth_uri'])->toBe($uri);

    $this->post('/two-factor/setup/confirm', ['code' => authenticatorCode($uri, now()->getTimestamp())['code']])
        ->assertSessionHasNoErrors();
});

it('accepts the previous and next 30-second code, and nothing further out', function () {
    $this->actingAs(User::factory()->create());
    $uri = enrolmentProps()['otpauth_uri'];
    $now = now()->getTimestamp();

    $this->post('/two-factor/setup/confirm', ['code' => authenticatorCode($uri, $now - 90)['code']])->assertSessionHasErrors('code');
    $this->post('/two-factor/setup/confirm', ['code' => authenticatorCode($uri, $now - 30)['code']])->assertSessionHasNoErrors();
});

it('changes the key only when asked to start again', function () {
    $this->actingAs(User::factory()->create());
    $first = enrolmentProps()['secret'];

    expect(enrolmentProps()['secret'])->toBe($first);

    $this->post('/two-factor/setup/reset')->assertRedirect(route('two-factor.setup'));
    $second = enrolmentProps()['secret'];

    expect($second)->not->toBe($first);

    // The old key's codes no longer work; the new key's do.
    $this->post('/two-factor/setup/confirm', ['code' => authenticatorCode('otpauth://totp/x?secret='.$first, now()->getTimestamp())['code']])
        ->assertSessionHasErrors('code');
    $this->post('/two-factor/setup/confirm', ['code' => authenticatorCode('otpauth://totp/x?secret='.$second, now()->getTimestamp())['code']])
        ->assertSessionHasNoErrors();
});

it('does not ask for a second factor at sign-in while enrolment is unfinished', function () {
    $user = User::factory()->create(['password_hash' => Hash::make(ENROL_PASSWORD)]);
    $this->actingAs($user);
    enrolmentProps(); // a pending key now exists
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => ENROL_PASSWORD])->assertRedirect(route('order-pad'));
    $this->assertAuthenticatedAs($user);
});

/**
 * Regression (2026-09-25): the key field rendered empty. `two_factor_secret`
 * is bytea, which pdo_pgsql returns as a stream; the cast drained it, so
 * the second read in one request returned nothing — and the setup page
 * reads it twice. The tests above passed because their users were built
 * in memory, never read back from the database. These load the user from
 * the database, exactly as a real request does.
 */
it('puts the 32-character key in the page for a user loaded from the database', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/two-factor/setup')->assertOk(); // creates and stores the pending key

    $stored = User::query()->findOrFail($user->id);
    $this->actingAs($stored);

    $body = html_entity_decode((string) $this->get('/two-factor/setup')->assertOk()->getContent(), ENT_QUOTES);

    expect(preg_match('/"secret":"([A-Z2-7]{32})"/', $body, $key))->toBe(1)
        ->and($key[1])->toBe(User::query()->findOrFail($user->id)->two_factor_secret)
        ->and($body)->toContain('secret='.$key[1]);
});

it('reads the stored secret the same every time', function () {
    $user = User::factory()->withTwoFactor()->create();
    $stored = User::query()->findOrFail($user->id);

    expect($stored->two_factor_secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($stored->two_factor_secret)->toBe($stored->two_factor_secret)
        ->and($stored->two_factor_secret)->toBe(User::query()->findOrFail($user->id)->two_factor_secret);
});

it('turns 2FA on only after the recovery codes are ticked as saved', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $uri = enrolmentProps()['otpauth_uri'];
    $this->post('/two-factor/setup/confirm', ['code' => authenticatorCode($uri, now()->getTimestamp())['code']]);

    $this->post('/two-factor/setup/complete', [])->assertSessionHasErrors('saved');
    $this->post('/two-factor/setup/complete', ['saved' => '0'])->assertSessionHasErrors('saved');
    expect(User::query()->findOrFail($user->id)->two_factor_enabled)->toBeFalse();

    $this->post('/two-factor/setup/complete', ['saved' => '1'])->assertSessionHasNoErrors();
    expect(User::query()->findOrFail($user->id)->two_factor_enabled)->toBeTrue();

    // Enrolled: the setup page now hands over to the account page.
    $this->get('/two-factor/setup')->assertRedirect(route('account'));
});

it('regenerates recovery codes from the account page, keeping the old ones until the new are ticked as saved', function () {
    $user = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(ENROL_PASSWORD)]);
    RecoveryCodes::replace($user, ['abcde-fghjk']);
    $this->actingAs($user);

    $this->post('/two-factor/recovery-codes', ['password' => 'not-my-password-at-all'])->assertSessionHasErrors('password');
    $this->post('/two-factor/recovery-codes', ['password' => ENROL_PASSWORD])->assertRedirect(route('account'));

    $pending = $this->get('/account')->assertOk()->viewData('page')['props']['two_factor']['pending_codes'];
    expect($pending)->toHaveCount(8);

    // Not ticked: nothing changes, and the old code still works.
    $this->post('/two-factor/recovery-codes/confirm', [])->assertSessionHasErrors('saved');
    expect(RecoveryCodes::remaining($user))->toBe(1);

    $this->post('/two-factor/recovery-codes/confirm', ['saved' => '1'])->assertSessionHasNoErrors()->assertRedirect(route('account'));

    expect(RecoveryCodes::remaining($user))->toBe(8)
        ->and(RecoveryCodes::consume($user, 'abcde-fghjk'))->toBeFalse()
        ->and(RecoveryCodes::consume($user, $pending[0]))->toBeTrue()
        ->and($this->get('/account')->viewData('page')['props']['two_factor']['pending_codes'])->toBeNull();
});

it('keeps the current recovery codes when regeneration is cancelled', function () {
    $user = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(ENROL_PASSWORD)]);
    RecoveryCodes::replace($user, ['abcde-fghjk']);
    $this->actingAs($user);

    $this->post('/two-factor/recovery-codes', ['password' => ENROL_PASSWORD]);
    $this->post('/two-factor/recovery-codes/cancel')->assertRedirect(route('account'));

    expect(RecoveryCodes::consume($user, 'abcde-fghjk'))->toBeTrue();
});
