<?php

use App\Domain\Identity\StaffOnboardingService;
use App\Domain\Notifications\Notices\PasswordReset;
use App\Filament\Resources\StaffUserResource;
use App\Filament\Resources\StaffUserResource\Pages\CreateStaffUser;
use App\Filament\Resources\StaffUserResource\Pages\ViewStaffUser;
use App\Jobs\SendNotification;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code]);
    }
});

function onboardingStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> */
function newStaffData(array $changes = []): array
{
    return array_replace([
        'first_name' => 'Morgan',
        'last_name' => 'Lee',
        'email' => 'morgan@example.com',
        'role_codes' => ['admin', 'warehouse'],
    ], $changes);
}

function onboardingToken(User $staff): string
{
    $job = Queue::pushed(SendNotification::class, fn (SendNotification $job) => $job->notice instanceof PasswordReset && $job->notice->userId === $staff->id)->last();
    expect($job)->not->toBeNull();

    return $job->notice->token;
}

it('creates pending staff including another admin through Filament and audits each grant', function () {
    $admin = onboardingStaff('admin');
    $this->actingAs($admin);

    $this->get('/admin/staff')->assertOk();
    $this->get('/admin/staff/create')->assertOk();
    Livewire::test(CreateStaffUser::class)
        ->fillForm(newStaffData())
        ->call('create')
        ->assertHasNoFormErrors();

    $staff = User::query()->where('email', 'morgan@example.com')->sole();
    expect($staff->status)->toBe('pending')
        ->and($staff->password_hash)->toBeNull()
        ->and($staff->email_verified_at)->toBeNull()
        ->and($staff->roles()->orderBy('code')->pluck('code')->all())->toBe(['admin', 'warehouse'])
        ->and(RoleUser::query()->where('user_id', $staff->id)->pluck('granted_by_user_id')->all())->toBe([$admin->id, $admin->id]);

    expect(array_keys(StaffUserResource::getPages()))->toBe(['index', 'create', 'view'])
        ->and($admin->can('update', $staff))->toBeFalse()
        ->and($admin->can('delete', $staff))->toBeFalse();

    $this->get('/admin/staff/'.$staff->id)->assertOk();
    $this->get('/admin/staff/'.$admin->id)->assertOk();
    Auth::logout();
    $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $entries = AuditLog::query()->where('subject_type', 'user')->where('subject_id', $staff->id)->get();
    expect($entries->pluck('action')->all())->toBe([
        'auth.staff_created', 'permission.staff_role_granted', 'permission.staff_role_granted', 'auth.staff_onboarding_requested',
    ])->and($entries->every(fn (AuditLog $entry) => $entry->actor_user_id === $admin->id))->toBeTrue()
        ->and($entries->toJson())->not->toContain($staff->email);

    expect(NotificationLog::query()->where('user_id', $staff->id)->sole()->notification_key)->toBe('auth.password_reset');
    expect(Password::broker()->getRepository()->exists($staff, onboardingToken($staff)))->toBeTrue();
});

it('keeps the staff directory and creation unavailable to non-admins and customers', function () {
    $staff = onboardingStaff('warehouse');

    $this->get('/admin/staff')->assertRedirect('/login');
    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $user = onboardingStaff($role);
        $this->actingAs($user)->get('/admin/staff')->assertForbidden();
        $this->get('/admin/staff/create')->assertForbidden();
        $this->get('/admin/staff/'.$staff->id)->assertForbidden();
        expect($user->can('create', User::class))->toBeFalse();
        expect(fn () => app(StaffOnboardingService::class)->create(newStaffData(), $user))
            ->toThrow(AuthorizationException::class);
    }

    $customer = User::factory()->create();
    $this->actingAs($customer)->get('/admin/staff')->assertForbidden();
    expect($customer->can('create', User::class))->toBeFalse();
    expect(User::query()->where('email', 'morgan@example.com')->exists())->toBeFalse();
});

it('rejects an existing customer email rather than turning that account into staff', function () {
    $admin = onboardingStaff('admin');
    $customer = User::factory()->create(['email' => 'morgan@example.com']);

    expect(fn () => app(StaffOnboardingService::class)->create(newStaffData(), $admin))
        ->toThrow(ValidationException::class);
    expect($customer->roles()->exists())->toBeFalse();
    expect(NotificationLog::query()->where('user_id', $customer->id)->exists())->toBeFalse();
    expect(AuditLog::query()->where('subject_type', 'user')->where('subject_id', $customer->id)->exists())->toBeFalse();
});

it('rejects empty, unknown or injected role grants', function () {
    $admin = onboardingStaff('admin');
    $service = app(StaffOnboardingService::class);

    foreach ([[], ['not_a_role'], ['warehouse', 'not_a_role']] as $roles) {
        expect(fn () => $service->create(newStaffData(['role_codes' => $roles]), $admin))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => $service->create(newStaffData(['email' => "morgan@example.com\r\nBcc: x@example.com"]), $admin))
        ->toThrow(ValidationException::class);
    expect(User::query()->where('email', 'morgan@example.com')->exists())->toBeFalse();
});

it('rolls back user grants and audit if onboarding fails before commit', function () {
    $admin = onboardingStaff('admin');

    try {
        DB::transaction(function () use ($admin): void {
            app(StaffOnboardingService::class)->create(newStaffData(), $admin);
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated failure');
    }

    expect(User::query()->where('email', 'morgan@example.com')->exists())->toBeFalse();
    expect(AuditLog::query()->where('action', 'auth.staff_created')->exists())->toBeFalse();
    expect(NotificationLog::query()->where('notification_key', 'auth.password_reset')->exists())->toBeFalse();
    Queue::assertNotPushed(SendNotification::class);
});

it('resends only for a pending staff account and replaces the old token', function () {
    $admin = onboardingStaff('admin');
    $service = app(StaffOnboardingService::class);
    $staff = $service->create(newStaffData(['role_codes' => ['warehouse']]), $admin);
    $oldToken = onboardingToken($staff);
    $this->actingAs($admin);

    expect($admin->can('resendStaffOnboarding', $staff))->toBeTrue();
    expect(fn () => $service->resendLink($staff, $admin))->toThrow(ValidationException::class);
    $this->travel(61)->seconds();
    Livewire::test(ViewStaffUser::class, ['record' => $staff->id])->callAction('resendSetupLink');

    $newToken = onboardingToken($staff);
    expect($newToken)->not->toBe($oldToken);
    expect(Password::broker()->getRepository()->exists($staff, $oldToken))->toBeFalse();
    expect(Password::broker()->getRepository()->exists($staff, $newToken))->toBeTrue();
    expect(AuditLog::query()->where('action', 'auth.staff_onboarding_requested')->where('subject_id', $staff->id)->count())->toBe(2);

    $staff->forceFill(['password_hash' => Hash::make('long-enough-password'), 'status' => 'active'])->save();
    expect($admin->can('resendStaffOnboarding', $staff))->toBeFalse();
    expect(fn () => $service->resendLink($staff, $admin))->toThrow(AuthorizationException::class);
});
