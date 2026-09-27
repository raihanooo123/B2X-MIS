<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\StaffRoleService;
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
function staffWithRoles(array $roles, string $status = 'active'): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);
    foreach ($roles as $code) {
        RoleUser::create(['role_id' => Role::query()->where('code', $code)->value('id'), 'user_id' => $user->id]);
    }

    return $user;
}

/** @return list<string> */
function roleCodes(User $user): array
{
    return $user->roles()->orderBy('code')->pluck('code')->all();
}

function roleAudit(User $subject): Collection
{
    return AuditLog::query()->where('subject_type', 'user')->where('subject_id', $subject->id)
        ->where('event_family', 'permission')->orderBy('id')->get();
}

it('lets an admin grant and revoke roles from the staff detail page, recording grantor and audit', function () {
    $admin = staffWithRoles(['admin']);
    $staff = staffWithRoles(['warehouse']);
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->assertActionVisible('grantRole')
        ->assertActionVisible('revokeRole')
        ->callAction('grantRole', data: ['role' => 'accounts'])
        ->assertHasNoActionErrors();

    expect(roleCodes($staff))->toBe(['accounts', 'warehouse'])
        ->and(RoleUser::query()->where('user_id', $staff->id)->where('role_id', Role::query()->where('code', 'accounts')->value('id'))->value('granted_by_user_id'))
        ->toBe($admin->id);

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->callAction('revokeRole', data: ['role' => 'warehouse'])
        ->assertHasNoActionErrors();

    expect(roleCodes($staff))->toBe(['accounts']);

    $entries = roleAudit($staff);
    expect($entries->pluck('action')->all())->toBe(['permission.staff_role_granted', 'permission.staff_role_revoked'])
        ->and($entries->every(fn (AuditLog $entry) => $entry->actor_type === 'user' && $entry->actor_user_id === $admin->id))->toBeTrue()
        ->and($entries[0]->before)->toBeNull()
        ->and($entries[0]->after)->toBe(['role' => 'accounts'])
        ->and($entries[1]->before)->toEqual(['role' => 'warehouse', 'granted_by_user_id' => null])
        ->and($entries[1]->after)->toBeNull();
});

it('lets an admin grant admin to other staff and revoke it while another active admin remains', function () {
    $admin = staffWithRoles(['admin']);
    $staff = staffWithRoles(['rep'], 'pending');
    $service = app(StaffRoleService::class);

    $service->grant($staff, 'admin', $admin);
    expect(roleCodes($staff))->toBe(['admin', 'rep']);

    $service->revoke($staff, 'admin', $admin);
    expect(roleCodes($staff))->toBe(['rep']);

    $entries = roleAudit($staff);
    expect($entries)->toHaveCount(2)
        ->and($entries[1]->before)->toEqual(['role' => 'admin', 'granted_by_user_id' => $admin->id]);
});

it('denies role management to non-admin staff, customers and inactive admins', function () {
    $staff = staffWithRoles(['warehouse', 'rep']);
    $service = app(StaffRoleService::class);

    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $actor = staffWithRoles([$role]);
        $this->actingAs($actor)->get('/admin/staff/'.$staff->id)->assertForbidden();
        expect($actor->can('manageStaffRoles', $staff))->toBeFalse();
        expect(fn () => $service->grant($staff, 'accounts', $actor))->toThrow(AuthorizationException::class);
        expect(fn () => $service->revoke($staff, 'rep', $actor))->toThrow(AuthorizationException::class);
    }

    $customer = User::factory()->create();
    expect(fn () => $service->grant($staff, 'admin', $customer))->toThrow(AuthorizationException::class);

    $suspendedAdmin = staffWithRoles(['admin'], 'suspended');
    expect(fn () => $service->grant($staff, 'accounts', $suspendedAdmin))->toThrow(AuthorizationException::class);

    expect(roleCodes($staff))->toBe(['rep', 'warehouse'])
        ->and(roleAudit($staff))->toBeEmpty();
});

it('never grants a staff role to a customer or to a suspended or closed account', function () {
    $admin = staffWithRoles(['admin']);
    $service = app(StaffRoleService::class);
    $customer = User::factory()->create();
    $suspended = staffWithRoles(['warehouse'], 'suspended');
    $closed = staffWithRoles(['warehouse'], 'closed');

    expect(fn () => $service->grant($customer, 'warehouse', $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->grant($suspended, 'accounts', $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->grant($closed, 'accounts', $admin))->toThrow(AuthorizationException::class);

    expect($customer->roles()->exists())->toBeFalse()
        ->and(roleCodes($suspended))->toBe(['warehouse'])
        ->and(roleCodes($closed))->toBe(['warehouse'])
        ->and(AuditLog::query()->where('event_family', 'permission')->exists())->toBeFalse();
});

it('prohibits administrators from changing their own roles, even if the policy is bypassed', function () {
    $admin = staffWithRoles(['admin', 'warehouse']);
    staffWithRoles(['admin']);
    $service = app(StaffRoleService::class);
    $this->actingAs($admin);

    Livewire::test(ViewStaffUser::class, ['record' => $admin->id])
        ->assertActionHidden('grantRole')
        ->assertActionHidden('revokeRole');
    expect($admin->can('manageStaffRoles', $admin))->toBeFalse();
    expect(fn () => $service->grant($admin, 'accounts', $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->revoke($admin, 'warehouse', $admin))->toThrow(AuthorizationException::class);

    Gate::before(fn () => true);
    expect(fn () => $service->revoke($admin, 'admin', $admin))->toThrow(AuthorizationException::class);

    expect(roleCodes($admin))->toBe(['admin', 'warehouse'])
        ->and(roleAudit($admin))->toBeEmpty();
});

it('refuses to remove a staff member\'s final role', function () {
    $admin = staffWithRoles(['admin']);
    $staff = staffWithRoles(['warehouse']);
    $this->actingAs($admin);

    expect(fn () => app(StaffRoleService::class)->revoke($staff, 'warehouse', $admin))
        ->toThrow(ValidationException::class);

    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])
        ->callAction('revokeRole', data: ['role' => 'warehouse'])
        ->assertNotified('A staff member must keep at least one role. Suspension is a separate action.');

    expect(roleCodes($staff))->toBe(['warehouse'])
        ->and(roleAudit($staff))->toBeEmpty();
});

it('keeps the last active administrator, counting only active admins, even if the policy is bypassed', function () {
    $lastActive = staffWithRoles(['admin', 'warehouse']);
    staffWithRoles(['admin'], 'pending');
    staffWithRoles(['admin'], 'suspended');
    $actor = staffWithRoles(['accounts']);

    Gate::before(fn () => true);
    expect(fn () => app(StaffRoleService::class)->revoke($lastActive, 'admin', $actor))
        ->toThrow(ValidationException::class, 'The last active administrator cannot lose the admin role.');

    expect(roleCodes($lastActive))->toBe(['admin', 'warehouse'])
        ->and(roleAudit($lastActive))->toBeEmpty();
});

it('rejects unknown, duplicate and absent role changes without auditing', function () {
    $admin = staffWithRoles(['admin']);
    $staff = staffWithRoles(['warehouse', 'rep']);
    $service = app(StaffRoleService::class);

    expect(fn () => $service->grant($staff, 'owner', $admin))->toThrow(ValidationException::class);
    expect(fn () => $service->grant($staff, 'warehouse', $admin))->toThrow(ValidationException::class);
    expect(fn () => $service->revoke($staff, 'accounts', $admin))->toThrow(ValidationException::class);

    expect(roleCodes($staff))->toBe(['rep', 'warehouse'])
        ->and(roleAudit($staff))->toBeEmpty();
});

it('re-reads actor authority and the subject\'s roles under lock rather than trusting stale models', function () {
    $first = staffWithRoles(['admin']);
    $second = staffWithRoles(['admin']);
    $staff = staffWithRoles(['warehouse']);
    $service = app(StaffRoleService::class);

    $staleSecond = User::query()->findOrFail($second->id);
    $staleStaff = User::query()->with('roles')->findOrFail($staff->id);

    // Another administrator grants the same role first; the stale view must not duplicate it.
    $service->grant($staff, 'accounts', $first);
    expect(fn () => $service->grant($staleStaff, 'accounts', $staleSecond))->toThrow(ValidationException::class);

    // The first admin removes the second admin's authority; the second's stale model is refused.
    RoleUser::create(['role_id' => Role::query()->where('code', 'rep')->value('id'), 'user_id' => $second->id]);
    $service->revoke($second, 'admin', $first);
    expect(fn () => $service->revoke($first, 'admin', $staleSecond))->toThrow(AuthorizationException::class);

    expect(roleCodes($first))->toBe(['admin'])
        ->and(roleCodes($staff))->toBe(['accounts', 'warehouse'])
        ->and(roleAudit($staff))->toHaveCount(1);
});

it('rolls back the role change and its audit row together', function () {
    $admin = staffWithRoles(['admin']);
    $staff = staffWithRoles(['warehouse']);

    try {
        DB::transaction(function () use ($staff, $admin): void {
            app(StaffRoleService::class)->grant($staff, 'accounts', $admin);
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated failure');
    }

    expect(roleCodes($staff))->toBe(['warehouse'])
        ->and(roleAudit($staff))->toBeEmpty();
});

it('accepts only the approved shape for a role revocation audit entry', function () {
    $entry = fn (array $before, array $after = []) => new AuditEntry(
        action: AuditAction::StaffRoleRevoked,
        actorType: 'user',
        actorUserId: 1,
        subjectType: 'user',
        subjectId: 2,
        before: $before,
        after: $after,
    );
    $logger = new AuditLogger;

    foreach ([
        $entry(['role' => 'warehouse']),
        $entry(['role' => 'owner', 'granted_by_user_id' => null]),
        $entry(['role' => 'warehouse', 'granted_by_user_id' => '1']),
        $entry(['role' => 'warehouse', 'granted_by_user_id' => null], ['role' => 'warehouse']),
    ] as $invalid) {
        expect(fn () => $logger->record($invalid))->toThrow(InvalidArgumentException::class);
    }

    $logger->record($entry(['role' => 'warehouse', 'granted_by_user_id' => 1]));
    expect(AuditLog::query()->sole()->action)->toBe('permission.staff_role_revoked');
});
