<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\StaffSuspensionService;
use App\Filament\Resources\StaffUserResource\Pages\ViewStaffUser;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
});

/** @param list<string> $roles */
function suspensionStaff(array $roles, string $status = 'active'): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);
    foreach ($roles as $code) {
        RoleUser::create(['role_id' => Role::query()->where('code', $code)->value('id'), 'user_id' => $user->id]);
    }

    return $user;
}

function openSession(User $user): void
{
    DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(20)),
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

function statusAudit(User $subject): Collection
{
    return AuditLog::query()->where('subject_type', 'user')->where('subject_id', $subject->id)
        ->whereIn('action', ['auth.staff_suspended', 'auth.staff_reinstated'])->orderBy('id')->get();
}

it('lets an admin suspend and reinstate staff from the detail page, ending sessions and auditing both', function () {
    $admin = suspensionStaff(['admin']);
    $staff = suspensionStaff(['warehouse']);
    openSession($staff);
    openSession($admin);
    Password::broker()->getRepository()->create($staff);
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->assertActionVisible('suspend')
        ->assertActionHidden('reinstate')
        ->callAction('suspend')
        ->assertHasNoActionErrors();

    expect($staff->fresh()->status)->toBe('suspended')
        ->and(roleCodesOf($staff))->toBe(['warehouse'])
        ->and(DB::table('sessions')->where('user_id', $staff->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $staff->email)->exists())->toBeFalse();

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->assertActionHidden('suspend')
        ->assertActionVisible('reinstate')
        ->callAction('reinstate')
        ->assertHasNoActionErrors();

    expect($staff->fresh()->status)->toBe('active');

    $entries = statusAudit($staff);
    expect($entries->pluck('action')->all())->toBe(['auth.staff_suspended', 'auth.staff_reinstated'])
        ->and($entries->every(fn (AuditLog $entry) => $entry->actor_user_id === $admin->id && $entry->event_family === 'auth'))->toBeTrue()
        ->and($entries[0]->before)->toBe(['status' => 'active'])
        ->and($entries[0]->after)->toBe(['status' => 'suspended'])
        ->and($entries[1]->before)->toBe(['status' => 'suspended'])
        ->and($entries[1]->after)->toBe(['status' => 'active']);
});

it('signs a suspended staff member out on their next request', function () {
    $admin = suspensionStaff(['admin']);
    $staff = suspensionStaff(['warehouse']);

    $this->actingAs($staff)->get('/admin')->assertOk();
    app(StaffSuspensionService::class)->suspend($staff, $admin);

    $this->get('/admin')->assertRedirect('/login');
    $this->assertGuest();
});

it('denies suspension and reinstatement to non-admin staff, customers and inactive admins', function () {
    $staff = suspensionStaff(['warehouse']);
    $suspended = suspensionStaff(['rep'], 'suspended');
    $service = app(StaffSuspensionService::class);

    $actors = [User::factory()->create(), suspensionStaff(['admin'], 'suspended')];
    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $actors[] = suspensionStaff([$role]);
    }

    foreach ($actors as $actor) {
        expect($actor->can('suspendStaff', $staff))->toBeFalse();
        expect(fn () => $service->suspend($staff, $actor))->toThrow(AuthorizationException::class);
        expect(fn () => $service->reinstate($suspended, $actor))->toThrow(AuthorizationException::class);
    }

    expect($staff->fresh()->status)->toBe('active')
        ->and($suspended->fresh()->status)->toBe('suspended')
        ->and(statusAudit($staff))->toBeEmpty();
});

it('only suspends active staff and only reinstates suspended staff with a password', function () {
    $admin = suspensionStaff(['admin']);
    $service = app(StaffSuspensionService::class);
    $customer = User::factory()->create();
    $pending = suspensionStaff(['warehouse'], 'pending');
    $closed = suspensionStaff(['warehouse'], 'closed');
    $active = suspensionStaff(['warehouse']);
    $passwordless = suspensionStaff(['warehouse'], 'suspended');
    $passwordless->forceFill(['password_hash' => null])->save();

    expect(fn () => $service->suspend($customer, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->suspend($pending, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->suspend($closed, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reinstate($active, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reinstate($closed, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reinstate($passwordless, $admin))->toThrow(AuthorizationException::class);

    expect($customer->fresh()->status)->toBe('active')
        ->and($pending->fresh()->status)->toBe('pending')
        ->and($closed->fresh()->status)->toBe('closed')
        ->and($passwordless->fresh()->status)->toBe('suspended')
        ->and(AuditLog::query()->whereIn('action', ['auth.staff_suspended', 'auth.staff_reinstated'])->exists())->toBeFalse();
});

it('prohibits administrators from suspending themselves, even if the policy is bypassed', function () {
    $admin = suspensionStaff(['admin']);
    suspensionStaff(['admin']);
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $admin->id])
        ->assertActionHidden('suspend')
        ->assertActionHidden('reinstate');

    Gate::before(fn () => true);
    expect(fn () => app(StaffSuspensionService::class)->suspend($admin, $admin))->toThrow(AuthorizationException::class);
    expect($admin->fresh()->status)->toBe('active');
});

it('lets an admin suspend another admin while another active admin remains', function () {
    $admin = suspensionStaff(['admin']);
    $other = suspensionStaff(['admin']);

    app(StaffSuspensionService::class)->suspend($other, $admin);

    expect($other->fresh()->status)->toBe('suspended');
});

it('keeps the last active administrator, even if the policy is bypassed', function () {
    $lastActive = suspensionStaff(['admin']);
    suspensionStaff(['admin'], 'pending');
    $actor = suspensionStaff(['accounts']);

    Gate::before(fn () => true);
    expect(fn () => app(StaffSuspensionService::class)->suspend($lastActive, $actor))
        ->toThrow(ValidationException::class, 'The last active administrator cannot be suspended.');

    expect($lastActive->fresh()->status)->toBe('active')
        ->and(statusAudit($lastActive))->toBeEmpty();
});

it('re-reads the actor and subject under lock rather than trusting stale models', function () {
    $first = suspensionStaff(['admin']);
    $second = suspensionStaff(['admin']);
    $staff = suspensionStaff(['warehouse']);
    $service = app(StaffSuspensionService::class);

    $staleStaff = User::query()->findOrFail($staff->id);
    $staleSecond = User::query()->findOrFail($second->id);

    $service->suspend($staff, $first);
    expect(fn () => $service->suspend($staleStaff, $staleSecond))->toThrow(AuthorizationException::class);

    $service->suspend($second, $first);
    expect(fn () => $service->reinstate($staff, $staleSecond))->toThrow(AuthorizationException::class);

    expect($staff->fresh()->status)->toBe('suspended')
        ->and(statusAudit($staff))->toHaveCount(1);
});

it('rolls back the suspension, session removal and audit row together', function () {
    $admin = suspensionStaff(['admin']);
    $staff = suspensionStaff(['warehouse']);
    openSession($staff);

    try {
        DB::transaction(function () use ($staff, $admin): void {
            app(StaffSuspensionService::class)->suspend($staff, $admin);
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated failure');
    }

    expect($staff->fresh()->status)->toBe('active')
        ->and(DB::table('sessions')->where('user_id', $staff->id)->exists())->toBeTrue()
        ->and(statusAudit($staff))->toBeEmpty();
});

it('accepts only status transitions matching the audit action', function () {
    $entry = fn (AuditAction $action, array $before, array $after) => new AuditEntry(
        action: $action,
        actorType: 'user',
        actorUserId: 1,
        subjectType: 'user',
        subjectId: 2,
        before: $before,
        after: $after,
    );
    $logger = new AuditLogger;

    foreach ([
        $entry(AuditAction::StaffSuspended, ['status' => 'pending'], ['status' => 'suspended']),
        $entry(AuditAction::StaffSuspended, ['status' => 'active'], ['status' => 'closed']),
        $entry(AuditAction::StaffReinstated, ['status' => 'active'], ['status' => 'suspended']),
        $entry(AuditAction::StaffReinstated, [], ['status' => 'active']),
    ] as $invalid) {
        expect(fn () => $logger->record($invalid))->toThrow(InvalidArgumentException::class);
    }

    $logger->record($entry(AuditAction::StaffSuspended, ['status' => 'active'], ['status' => 'suspended']));
    expect(AuditLog::query()->sole()->action)->toBe('auth.staff_suspended');
});

/** @return list<string> */
function roleCodesOf(User $user): array
{
    return $user->roles()->orderBy('code')->pluck('code')->all();
}
