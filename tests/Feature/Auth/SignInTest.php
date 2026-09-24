<?php

use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\Totp;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * 05.13 §6 (sign-in, lockout) and §12 (2FA challenge, staff enforcement).
 */
const SIGN_IN_PASSWORD = 'a-long-enough-passphrase';

function signInUser(array $attributes = []): User
{
    return User::factory()->create(['password_hash' => Hash::make(SIGN_IN_PASSWORD)] + $attributes);
}

function signInStaff(string $roleCode, bool $twoFactor): User
{
    $factory = User::factory()->state(['password_hash' => Hash::make(SIGN_IN_PASSWORD)]);
    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
    RoleUser::create(['role_id' => Role::factory()->create(['code' => $roleCode])->id, 'user_id' => $user->id]);

    return $user;
}

function currentTotp(): string
{
    return Totp::codeAt(UserFactory::TOTP_SECRET, intdiv(now()->getTimestamp(), Totp::PERIOD));
}

it('signs in with the right password and lands on the order pad', function () {
    $user = signInUser();

    $this->post('/login', ['email' => strtoupper($user->email), 'password' => SIGN_IN_PASSWORD])
        ->assertRedirect(route('order-pad'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('gives one generic message for a wrong password, an unknown email and an inactive account', function () {
    $user = signInUser();
    $suspended = signInUser(['status' => 'suspended']);
    $message = "Those details don't match an account.";

    $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password-at-all'])->assertSessionHasErrors(['email' => $message]);
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => SIGN_IN_PASSWORD])->assertSessionHasErrors(['email' => $message]);
    $this->post('/login', ['email' => $suspended->email, 'password' => SIGN_IN_PASSWORD])->assertSessionHasErrors(['email' => $message]);

    $this->assertGuest();
});

it('locks the identifier for 1 minute after 5 failures, refusing even the right password', function () {
    $user = signInUser();

    foreach (range(1, 4) as $i) {
        $this->post('/login', ['email' => $user->email, 'password' => "wrong-password-{$i}xx"]);
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-5xx'])
        ->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 1 minute.']);

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(61)->seconds();
    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('escalates to 5 minutes at 10 failures and 30 minutes at 15', function () {
    $email = 'target@example.com';
    $fail = fn () => $this->post('/login', ['email' => $email, 'password' => 'wrong-password-xxx']);

    foreach (range(1, 5) as $_) {
        $fail();
    }
    $this->travel(61)->seconds();
    foreach (range(6, 9) as $_) {
        $fail();
    }
    $fail()->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 5 minutes.']);

    $this->travel(301)->seconds();
    foreach (range(11, 14) as $_) {
        $fail();
    }
    $fail()->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 30 minutes.']);
});

it('counts unknown identifiers exactly like known ones', function () {
    foreach (range(1, 5) as $_) {
        $response = $this->post('/login', ['email' => 'no-such-user@example.com', 'password' => 'wrong-password-xxx']);
    }

    $response->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 1 minute.']);
});

it('lets the failure count lapse 15 minutes after the last failure', function () {
    $user = signInUser();

    foreach (range(1, 4) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-xxx']);
    }
    $this->travel(16)->minutes();

    // A fresh count: this is failure 1, not 5 — no lock.
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-xxx'])
        ->assertSessionHasErrors(['email' => "Those details don't match an account."]);
});

it('clears the identifier count on success but not the IP count', function () {
    $user = signInUser();
    $other = signInUser();

    foreach (range(1, 4) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-xxx']);
    }
    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');

    // The IP keeps its four failures: one more, against any account, is
    // its fifth and locks it for a minute.
    $this->post('/login', ['email' => $other->email, 'password' => 'wrong-password-xxx'])
        ->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 1 minute.']);

    // The identifier started again at sign-in: four failures do not lock
    // it (the IP is at six to nine — between its thresholds).
    $this->travel(61)->seconds();
    foreach (range(1, 4) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-xxx'])
            ->assertSessionHasErrors(['email' => "Those details don't match an account."]);
    }
});

it('rehashes a bcrypt password to Argon2id on sign-in (07 §6.1)', function () {
    $user = signInUser(['password_hash' => password_hash(SIGN_IN_PASSWORD, PASSWORD_BCRYPT)]);

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD])->assertRedirect();

    expect(Hash::info((string) $user->fresh()->password_hash)['algoName'])->toBe('argon2id');
});

it('asks for the second factor before the session is authenticated', function () {
    $user = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(SIGN_IN_PASSWORD)]);

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => currentTotp()])->assertRedirect(route('order-pad'));
    $this->assertAuthenticatedAs($user);
});

it('refuses a TOTP code that has already been used', function () {
    $user = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(SIGN_IN_PASSWORD)]);
    $code = currentTotp();

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);
    $this->post('/two-factor-challenge', ['code' => $code]);
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('accepts each recovery code once', function () {
    $user = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(SIGN_IN_PASSWORD)]);
    RecoveryCodes::replace($user, ['abcde-fghjk', 'mnpqr-stuvw']);

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);
    $this->post('/two-factor-challenge', ['code' => 'ABCDE FGHJK'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);
    $this->post('/two-factor-challenge', ['code' => 'abcde-fghjk'])->assertSessionHasErrors('code');
    $this->assertGuest();

    expect(RecoveryCodes::remaining($user))->toBe(1);
});

it('counts failed second factors towards the lockout', function () {
    $user = User::factory()->withTwoFactor()->create(['password_hash' => Hash::make(SIGN_IN_PASSWORD)]);
    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);

    foreach (range(1, 4) as $_) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }
    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Please try again in 1 minute.']);

    $this->post('/two-factor-challenge', ['code' => currentTotp()])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('sends staff without 2FA to enrolment from every page, the panel and the API', function () {
    $staff = signInStaff('warehouse', twoFactor: false);

    $this->post('/login', ['email' => $staff->email, 'password' => SIGN_IN_PASSWORD])
        ->assertRedirect(route('two-factor.setup'));

    $this->get('/order-pad')->assertRedirect(route('two-factor.setup'));
    $this->get('/admin')->assertRedirect(route('two-factor.setup'));
    $this->getJson('/api/v1/cart')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
    $this->get('/two-factor/setup')->assertOk();
});

it('sends signed-out visitors to the admin panel to the shared sign-in page', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('takes staff with 2FA through the challenge and on to the panel', function () {
    $staff = signInStaff('admin', twoFactor: true);

    $this->get('/admin');
    $this->post('/login', ['email' => $staff->email, 'password' => SIGN_IN_PASSWORD])->assertRedirect(route('two-factor.challenge'));
    $this->post('/two-factor-challenge', ['code' => currentTotp()])->assertRedirect(url('/admin'));
});

it('signs out', function () {
    $user = signInUser();
    $this->post('/login', ['email' => $user->email, 'password' => SIGN_IN_PASSWORD]);

    $this->post('/logout')->assertRedirect(route('order-pad'));
    $this->assertGuest();
});
