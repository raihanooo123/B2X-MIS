<?php

use App\Domain\Audit\AuditLogger;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function auditViewerStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

it('lists failed sign-ins for admins without exposing the raw identifier', function () {
    config()->set('audit.identifier_key', str_repeat('a', 64));
    config()->set('audit.identifier_key_version', 'test-v1');
    (new AuditLogger)->failedSignIn('person@example.com', '127.0.0.1', 'Test browser');
    $entry = AuditLog::query()->firstOrFail();

    $this->actingAs(auditViewerStaff('admin'))
        ->get('/admin/audit-logs')
        ->assertOk()
        ->assertSee('Audit log')
        ->assertSee('auth.sign_in_failed')
        ->assertDontSee('person@example.com');

    $this->get('/admin/audit-logs/'.$entry->id)
        ->assertOk()
        ->assertSee('identifier_fingerprint')
        ->assertDontSee('person@example.com');

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(array_keys(AuditLogResource::getPages()))->toBe(['index', 'view']);
});

it('keeps audit history inaccessible to guests and every non-admin staff role', function () {
    config()->set('audit.identifier_key', str_repeat('a', 64));
    (new AuditLogger)->failedSignIn('person@example.com', '127.0.0.1', 'Test browser');
    $entry = AuditLog::query()->firstOrFail();

    $this->get('/admin/audit-logs')->assertRedirect('/login');

    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $user = auditViewerStaff($role);
        $this->actingAs($user)->get('/admin/audit-logs')->assertForbidden();
        $this->get('/admin/audit-logs/'.$entry->id)->assertForbidden();

        expect($user->can('viewAny', AuditLog::class))->toBeFalse()
            ->and($user->can('view', $entry))->toBeFalse();
    }

    $admin = auditViewerStaff('admin');
    $this->actingAs($admin);
    expect($admin->can('viewAny', AuditLog::class))->toBeTrue()
        ->and($admin->can('view', $entry))->toBeTrue()
        ->and($admin->can('create', AuditLog::class))->toBeFalse()
        ->and($admin->can('update', $entry))->toBeFalse()
        ->and($admin->can('delete', $entry))->toBeFalse()
        ->and($admin->can('deleteAny', AuditLog::class))->toBeFalse();
});

it('filters audit history by family, actor, company, subject, and date', function () {
    config()->set('audit.identifier_key', str_repeat('a', 64));
    (new AuditLogger)->failedSignIn('person@example.com', '127.0.0.1', 'Test browser');
    $failedSignIn = AuditLog::query()->where('action', 'auth.sign_in_failed')->firstOrFail();
    $actor = auditViewerStaff('accounts');
    $company = Company::factory()->create();
    $older = now()->subDays(2)->startOfDay()->addHours(12);

    // The logger currently has only one production action. These two rows are
    // test fixtures for the other event families already allowed by the schema.
    DB::table('audit_log')->insert([
        'occurred_at' => $older,
        'event_family' => 'permission',
        'action' => 'permission.changed',
        'actor_type' => 'user',
        'actor_user_id' => $actor->id,
        'company_id' => $company->id,
        'subject_type' => 'order',
        'subject_id' => 42,
    ]);
    $permission = AuditLog::query()->where('action', 'permission.changed')->firstOrFail();

    $this->actingAs(auditViewerStaff('admin'));

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$failedSignIn, $permission])
        ->filterTable('event_family', 'auth')
        ->assertCanSeeTableRecords([$failedSignIn])
        ->assertCanNotSeeTableRecords([$permission]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('actor_user_id', $actor->id)
        ->assertCanSeeTableRecords([$permission])
        ->assertCanNotSeeTableRecords([$failedSignIn]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('company_id', $company->id)
        ->assertCanSeeTableRecords([$permission])
        ->assertCanNotSeeTableRecords([$failedSignIn]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('subject', ['type' => 'order', 'id' => 42])
        ->assertCanSeeTableRecords([$permission])
        ->assertCanNotSeeTableRecords([$failedSignIn]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('occurred_at', ['from' => now()->subDay()->toDateString()])
        ->assertCanSeeTableRecords([$failedSignIn])
        ->assertCanNotSeeTableRecords([$permission]);
});
