<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\RecoveryCodes;
use App\Domain\Identity\StaffSuspensionService;
use App\Domain\Identity\StaffTwoFactorResetService;
use App\Domain\Notifications\NotificationKey;
use App\Filament\Resources\StaffUserResource\Pages\ViewStaffUser;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserTwoFactorRecoveryCode;
use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
});

/** @param list<string> $roles */
function twoFactorStaff(array $roles, string $status = 'active'): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);
    foreach ($roles as $code) {
        RoleUser::create(['role_id' => Role::query()->where('code', $code)->value('id'), 'user_id' => $user->id]);
    }
    RecoveryCodes::replace($user, ['abcde-fghjk', 'mnpqr-stuvw']);

    return $user;
}

function openTwoFactorSession(User $user): void
{
    DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(20)),
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

function twoFactorResetAudit(User $subject): Collection
{
    return AuditLog::query()->where('subject_type', 'user')->where('subject_id', $subject->id)
        ->where('action', 'auth.staff_two_factor_reset')->orderBy('id')->get();
}

function twoFactorNotices(User $user): Collection
{
    return DB::table('notification_log')->where('notification_key', NotificationKey::TwoFactorChanged->value)
        ->where('user_id', $user->id)->get();
}

function expectTwoFactorIntact(User $user): void
{
    $fresh = $user->fresh();
    expect($fresh->two_factor_enabled)->toBeTrue()
        ->and($fresh->two_factor_secret)->toBe(UserFactory::TOTP_SECRET)
        ->and(UserTwoFactorRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(twoFactorResetAudit($user))->toBeEmpty();
}

it('lets an admin reset staff 2FA from the detail page, ending sessions, auditing and notifying', function () {
    $admin = twoFactorStaff(['admin']);
    $staff = twoFactorStaff(['warehouse']);
    openTwoFactorSession($staff);
    openTwoFactorSession($admin);
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->assertActionVisible('resetTwoFactor')
        ->callAction('resetTwoFactor')
        ->assertHasNoActionErrors();

    $fresh = $staff->fresh();
    expect($fresh->two_factor_enabled)->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->status)->toBe('active')
        ->and($fresh->roles()->pluck('code')->all())->toBe(['warehouse'])
        ->and(UserTwoFactorRecoveryCode::query()->where('user_id', $staff->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $staff->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and(UserTwoFactorRecoveryCode::query()->where('user_id', $admin->id)->count())->toBe(2)
        ->and($admin->fresh()->two_factor_enabled)->toBeTrue();

    $entry = twoFactorResetAudit($staff)->sole();
    expect($entry->actor_user_id)->toBe($admin->id)
        ->and($entry->event_family)->toBe('auth')
        ->and($entry->before)->toBe(['two_factor_enabled' => true])
        ->and($entry->after)->toBe(['two_factor_enabled' => false])
        ->and($entry->reason)->toBeNull();

    $notice = twoFactorNotices($staff)->sole();
    expect($notice->recipient)->toBe($staff->email)
        ->and($notice->subject_type)->toBe('user')
        ->and((int) $notice->subject_id)->toBe($staff->id);

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->assertActionHidden('resetTwoFactor');
});

it('sends the reset staff member to enrolment on their next sign-in', function () {
    $admin = twoFactorStaff(['admin']);
    $staff = twoFactorStaff(['warehouse']);

    app(StaffTwoFactorResetService::class)->reset($staff, $admin);

    $this->actingAs($staff->fresh())->get('/admin')->assertRedirect(route('two-factor.setup'));
});

it('denies the reset to non-admin staff, customers and inactive admins', function () {
    $staff = twoFactorStaff(['warehouse']);
    $service = app(StaffTwoFactorResetService::class);

    $actors = [User::factory()->withTwoFactor()->create(), twoFactorStaff(['admin'], 'suspended'), twoFactorStaff(['admin'], 'pending')];
    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $actors[] = twoFactorStaff([$role]);
    }

    foreach ($actors as $actor) {
        expect($actor->can('resetStaffTwoFactor', $staff))->toBeFalse();
        expect(fn () => $service->reset($staff, $actor))->toThrow(AuthorizationException::class);
    }

    $this->actingAs(twoFactorStaff(['accounts']))->get("/admin/staff/{$staff->id}")->assertForbidden();

    expectTwoFactorIntact($staff);
    expect(twoFactorNotices($staff))->toBeEmpty();
});

it('refuses customers and staff who are not active', function () {
    $admin = twoFactorStaff(['admin']);
    $service = app(StaffTwoFactorResetService::class);
    $customer = User::factory()->withTwoFactor()->create();
    RecoveryCodes::replace($customer, ['abcde-fghjk', 'mnpqr-stuvw']);
    $subjects = [$customer, twoFactorStaff(['warehouse'], 'pending'), twoFactorStaff(['warehouse'], 'suspended'), twoFactorStaff(['warehouse'], 'closed')];

    foreach ($subjects as $subject) {
        expect($admin->can('resetStaffTwoFactor', $subject))->toBeFalse();
        expect(fn () => $service->reset($subject, $admin))->toThrow(AuthorizationException::class);
        expectTwoFactorIntact($subject);
    }
});

it('refuses staff with no 2FA enrolled, including an unconfirmed pending key', function () {
    $admin = twoFactorStaff(['admin']);
    $notEnrolled = twoFactorStaff(['warehouse']);
    $notEnrolled->forceFill(['two_factor_secret' => null, 'two_factor_enabled' => false])->save();
    $pendingKey = twoFactorStaff(['rep']);
    $pendingKey->forceFill(['two_factor_enabled' => false])->save();
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $notEnrolled->id])
        ->assertActionHidden('resetTwoFactor');

    foreach ([$notEnrolled, $pendingKey] as $subject) {
        expect(fn () => app(StaffTwoFactorResetService::class)->reset($subject, $admin))->toThrow(AuthorizationException::class);
        expect(twoFactorResetAudit($subject))->toBeEmpty();
    }

    expect($pendingKey->fresh()->two_factor_secret)->toBe(UserFactory::TOTP_SECRET);
});

it('prohibits administrators from resetting their own 2FA, even if the policy is bypassed', function () {
    $admin = twoFactorStaff(['admin']);
    twoFactorStaff(['admin']);
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $admin->id])
        ->assertActionHidden('resetTwoFactor');

    Gate::before(fn () => true);
    expect(fn () => app(StaffTwoFactorResetService::class)->reset($admin, $admin))->toThrow(AuthorizationException::class);
    expectTwoFactorIntact($admin);
});

it('lets an admin reset another admin', function () {
    $admin = twoFactorStaff(['admin']);
    $other = twoFactorStaff(['admin']);

    app(StaffTwoFactorResetService::class)->reset($other, $admin);

    expect($other->fresh()->two_factor_enabled)->toBeFalse()
        ->and($other->fresh()->status)->toBe('active');
});

it('re-reads the actor and subject under lock rather than trusting stale models', function () {
    $first = twoFactorStaff(['admin']);
    $second = twoFactorStaff(['admin']);
    $staff = twoFactorStaff(['warehouse']);
    $service = app(StaffTwoFactorResetService::class);

    $staleStaff = User::query()->findOrFail($staff->id);
    $staleSecond = User::query()->findOrFail($second->id);

    $service->reset($staff, $first);
    expect($staleStaff->two_factor_enabled)->toBeTrue();
    expect(fn () => $service->reset($staleStaff, $first))->toThrow(AuthorizationException::class);

    $other = twoFactorStaff(['rep']);
    app(StaffSuspensionService::class)->suspend($second, $first);
    expect(fn () => $service->reset($other, $staleSecond))->toThrow(AuthorizationException::class);

    expect(twoFactorResetAudit($staff))->toHaveCount(1)
        ->and(twoFactorNotices($staff))->toHaveCount(1);
    expectTwoFactorIntact($other);
});

it('rolls back the reset, session removal, audit row and notice together', function () {
    $admin = twoFactorStaff(['admin']);
    $staff = twoFactorStaff(['warehouse']);
    openTwoFactorSession($staff);

    try {
        DB::transaction(function () use ($staff, $admin): void {
            app(StaffTwoFactorResetService::class)->reset($staff, $admin);
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated failure');
    }

    expectTwoFactorIntact($staff);
    expect(DB::table('sessions')->where('user_id', $staff->id)->exists())->toBeTrue()
        ->and(twoFactorNotices($staff))->toBeEmpty();
});

it('accepts only the approved 2FA reset audit shape, never secrets or recovery codes', function () {
    $entry = fn (array $before, array $after) => new AuditEntry(
        action: AuditAction::StaffTwoFactorReset,
        actorType: 'user',
        actorUserId: 1,
        subjectType: 'user',
        subjectId: 2,
        before: $before,
        after: $after,
    );
    $logger = new AuditLogger;

    foreach ([
        $entry([], ['two_factor_enabled' => false]),
        $entry(['two_factor_enabled' => false], ['two_factor_enabled' => false]),
        $entry(['two_factor_enabled' => true], ['two_factor_enabled' => true]),
        $entry(['two_factor_enabled' => 1], ['two_factor_enabled' => 0]),
        $entry(['two_factor_enabled' => true, 'two_factor_secret' => UserFactory::TOTP_SECRET], ['two_factor_enabled' => false]),
        $entry(['two_factor_enabled' => true], ['two_factor_enabled' => false, 'recovery_codes' => 'abcde-fghjk']),
        $entry(['status' => 'active'], ['status' => 'suspended']),
    ] as $invalid) {
        expect(fn () => $logger->record($invalid))->toThrow(InvalidArgumentException::class);
    }

    $logger->record($entry(['two_factor_enabled' => true], ['two_factor_enabled' => false]));
    expect(AuditLog::query()->sole()->action)->toBe('auth.staff_two_factor_reset');
});
