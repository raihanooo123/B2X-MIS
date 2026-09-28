<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\Totp;
use App\Domain\Notifications\Notices\PasswordReset;
use App\Jobs\SendNotification;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 05.13 §15: a person's own authentication events are audited — who, how,
 * from where — and never with a password, code, token or raw identifier.
 */
const AUDIT_PASSWORD = 'a-long-enough-passphrase';

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
});

function auditUser(bool $twoFactor = false): User
{
    $factory = User::factory()->state(['password_hash' => Hash::make(AUDIT_PASSWORD)]);

    return ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
}

/** @return list<string> */
function authActions(): array
{
    return AuditLog::query()->orderBy('id')->where('action', 'not like', 'auth.sign_in_failed')->pluck('action')->all();
}

function ownEntry(string $action): AuditLog
{
    return AuditLog::query()->where('action', $action)->sole();
}

it('audits a password sign-in and a sign-out against the user, with where they came from', function () {
    $user = auditUser();

    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD], ['User-Agent' => 'Desk/1.0']);
    $this->post('/logout');

    expect(authActions())->toBe(['auth.signed_in', 'auth.signed_out']);
    $in = ownEntry('auth.signed_in');
    expect($in->actor_type)->toBe('user')
        ->and($in->actor_user_id)->toBe($user->id)
        ->and($in->subject_type)->toBe('user')
        ->and($in->subject_id)->toBe($user->id)
        ->and($in->after)->toEqual(['method' => 'password'])
        ->and($in->ip)->toBe('127.0.0.1')
        ->and($in->user_agent)->toBe('Desk/1.0')
        ->and(ownEntry('auth.signed_out')->actor_user_id)->toBe($user->id);
});

it('records the second factor used, and a recovery code with how many remain', function () {
    $user = auditUser(twoFactor: true);
    RecoveryCodes::replace($user, ['abcde-fghjk', 'mnpqr-stuvw']);

    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD]);
    expect(authActions())->toBe([]);

    $this->post('/two-factor-challenge', ['code' => 'abcde-fghjk'])->assertRedirect();

    expect(authActions())->toBe(['auth.signed_in', 'auth.recovery_code_used'])
        ->and(ownEntry('auth.signed_in')->after)->toEqual(['method' => 'recovery_code'])
        ->and(ownEntry('auth.recovery_code_used')->after)->toEqual(['remaining' => 1])
        ->and(AuditLog::query()->where('after', 'like', '%abcde%')->exists())->toBeFalse();

    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD]);
    $this->post('/two-factor-challenge', ['code' => Totp::codeAt((string) $user->two_factor_secret, intdiv(now()->getTimestamp(), Totp::PERIOD))]);

    expect(AuditLog::query()->where('action', 'auth.signed_in')->orderByDesc('id')->first()->after)->toEqual(['method' => 'two_factor']);
});

it('audits the lockout once, as a fingerprint, when the fifth failure starts it', function () {
    $user = auditUser();

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $user->email, 'password' => "wrong-password-{$i}xx"]);
    }
    // Refused while locked: no second lockout entry.
    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD]);

    $lockout = ownEntry('auth.locked_out');
    expect($lockout->actor_type)->toBe('anonymous')
        ->and($lockout->subject_id)->toBeNull()
        ->and($lockout->after)->toEqual([
            'identifier_fingerprint' => hash_hmac('sha256', $user->email, (string) config('audit.identifier_key')),
            'key_version' => (string) config('audit.identifier_key_version'),
            'locked_seconds' => 60,
        ])
        ->and(json_encode($lockout->after))->not->toContain($user->email)
        ->and(AuditLog::query()->where('action', 'auth.signed_in')->exists())->toBeFalse();
});

it('records why the system ended a session', function (string $reason) {
    $user = auditUser();
    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD]);

    match ($reason) {
        'idle' => $this->travel(12 * 60 + 1)->minutes(),
        // Active every 11 hours (under the idle limit) until past 7 days.
        'absolute' => (function () {
            foreach (range(1, 15) as $step) {
                $this->travel(11)->hours();
                $this->get('/order-pad')->assertOk();
            }
            $this->travel(4)->hours();
        })(),
        // The signed-in instance: the test keeps the guard's user between requests.
        'account_inactive' => auth()->user()->forceFill(['status' => 'suspended'])->save(),
    };
    $this->get('/order-pad')->assertRedirect(route('login'));

    expect(ownEntry('auth.session_expired')->after)->toEqual(['reason' => $reason])
        ->and(AuditLog::query()->where('action', 'auth.signed_out')->exists())->toBeFalse();
})->with(['idle', 'absolute', 'account_inactive']);

it('audits a reset request without revealing it, and the completed reset', function () {
    $user = auditUser();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');
    expect(AuditLog::query()->exists())->toBeFalse();

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
    $requested = ownEntry('auth.password_reset_requested');
    expect($requested->actor_type)->toBe('anonymous')
        ->and($requested->subject_id)->toBe($user->id)
        ->and($requested->after)->toBeNull();

    $token = Queue::pushed(SendNotification::class, fn (SendNotification $job) => $job->notice instanceof PasswordReset)->last()->notice->token;
    $new = 'a-brand-new-passphrase';
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new]);

    expect(authActions())->toBe(['auth.password_reset_requested', 'auth.password_reset_completed', 'auth.signed_in'])
        ->and(ownEntry('auth.password_reset_completed')->actor_user_id)->toBe($user->id)
        ->and(AuditLog::query()->where('after', 'like', "%{$token}%")->exists())->toBeFalse();
});

it('audits turning 2FA on, regenerating recovery codes and turning it off', function () {
    $user = auditUser();
    $this->actingAs($user);

    $secret = $this->get('/two-factor/setup')->viewData('page')['props']['secret'];
    $this->post('/two-factor/setup/confirm', ['code' => Totp::codeAt($secret, intdiv(now()->getTimestamp(), Totp::PERIOD))]);
    $this->post('/two-factor/setup/complete', ['saved' => '1']);

    $this->post('/two-factor/recovery-codes', ['password' => AUDIT_PASSWORD]);
    expect(authActions())->toBe(['auth.two_factor_enabled']);
    $this->post('/two-factor/recovery-codes/confirm', ['saved' => '1']);

    $this->delete('/two-factor', ['password' => AUDIT_PASSWORD]);

    expect(authActions())->toBe(['auth.two_factor_enabled', 'auth.recovery_codes_regenerated', 'auth.two_factor_disabled']);
    foreach (AuditLog::query()->get() as $entry) {
        expect([$entry->actor_user_id, $entry->subject_id, $entry->after])->toEqual([$user->id, $user->id, null])
            ->and(json_encode($entry->after))->not->toContain($secret);
    }
});

it('refuses an own-account entry that names someone else or carries extra data', function () {
    $entry = fn (AuditAction $action, array $after = [], ?int $actor = 1, string $actorType = 'user') => new AuditEntry(
        action: $action,
        actorType: $actorType,
        actorUserId: $actor,
        subjectType: 'user',
        subjectId: 1,
        after: $after,
    );
    $logger = new AuditLogger;

    foreach ([
        $entry(AuditAction::SignedIn, ['method' => 'password'], actor: 2),
        $entry(AuditAction::SignedIn, ['method' => 'magic_link']),
        $entry(AuditAction::SignedIn, ['method' => 'password', 'password' => 'secret']),
        $entry(AuditAction::SessionExpired, ['reason' => 'bored']),
        $entry(AuditAction::RecoveryCodeUsed, ['remaining' => -1]),
        $entry(AuditAction::RecoveryCodeUsed, ['code' => 'abcde-fghjk']),
        $entry(AuditAction::TwoFactorEnabled, ['two_factor_secret' => 'X']),
        $entry(AuditAction::PasswordResetRequested),
        $entry(AuditAction::SignedOut, actor: null, actorType: 'anonymous'),
    ] as $invalid) {
        expect(fn () => $logger->record($invalid))->toThrow(InvalidArgumentException::class);
    }

    expect(AuditLog::query()->exists())->toBeFalse();
});
