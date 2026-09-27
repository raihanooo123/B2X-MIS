<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\CustomerSuspensionService;
use App\Domain\Identity\StaffSuspensionService;
use App\Filament\Resources\CustomerUserResource;
use App\Filament\Resources\CustomerUserResource\Pages\ListCustomerUsers;
use App\Filament\Resources\CustomerUserResource\Pages\ViewCustomerUser;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
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
function customerAdminStaff(array $roles, string $status = 'active'): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status]);
    foreach ($roles as $code) {
        RoleUser::create(['role_id' => Role::query()->where('code', $code)->value('id'), 'user_id' => $user->id]);
    }

    return $user;
}

function companyMember(Company $company, string $role = 'buyer', string $status = 'active'): User
{
    $user = User::factory()->create(['status' => $status]);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function openCustomerSession(User $user): void
{
    DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(20)),
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

function customerStatusAudit(User $subject): Collection
{
    return AuditLog::query()->where('subject_type', 'user')->where('subject_id', $subject->id)
        ->whereIn('action', ['auth.customer_suspended', 'auth.customer_reinstated'])->orderBy('id')->get();
}

/** @return array<string, mixed> */
function companyAccountState(Company $company): array
{
    return $company->fresh()->only(['status', 'credit_limit_minor', 'credit_used_minor', 'credit_held_minor']);
}

it('lets an admin suspend and reinstate a customer user, ending sessions and tokens and auditing both', function () {
    $admin = customerAdminStaff(['admin']);
    $company = Company::factory()->create();
    companyMember($company, 'owner');
    $buyer = companyMember($company);
    $companyBefore = companyAccountState($company);
    openCustomerSession($buyer);
    openCustomerSession($admin);
    Password::broker()->getRepository()->create($buyer);
    $this->actingAs($admin);

    Livewire::test(ViewCustomerUser::class, ['record' => $buyer->id])
        ->assertActionVisible('suspend')
        ->assertActionHidden('reinstate')
        ->callAction('suspend')
        ->assertHasNoActionErrors();

    expect($buyer->fresh()->status)->toBe('suspended')
        ->and(DB::table('sessions')->where('user_id', $buyer->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $buyer->email)->exists())->toBeFalse()
        ->and(CompanyUser::query()->where('user_id', $buyer->id)->value('role'))->toBe('buyer')
        ->and(companyAccountState($company))->toBe($companyBefore);

    Livewire::test(ViewCustomerUser::class, ['record' => $buyer->id])
        ->assertActionHidden('suspend')
        ->assertActionVisible('reinstate')
        ->callAction('reinstate')
        ->assertHasNoActionErrors();

    expect($buyer->fresh()->status)->toBe('active')
        ->and(companyAccountState($company))->toBe($companyBefore);

    $entries = customerStatusAudit($buyer);
    expect($entries->pluck('action')->all())->toBe(['auth.customer_suspended', 'auth.customer_reinstated'])
        ->and($entries->every(fn (AuditLog $entry) => $entry->actor_user_id === $admin->id
            && $entry->event_family === 'auth' && $entry->company_id === null))->toBeTrue()
        ->and($entries[0]->before)->toBe(['status' => 'active'])
        ->and($entries[0]->after)->toBe(['status' => 'suspended'])
        ->and($entries[1]->before)->toBe(['status' => 'suspended'])
        ->and($entries[1]->after)->toBe(['status' => 'active']);
});

it('suspends a public customer with no company', function () {
    $admin = customerAdminStaff(['admin']);
    $customer = User::factory()->create();

    app(CustomerSuspensionService::class)->suspend($customer, $admin);

    expect($customer->fresh()->status)->toBe('suspended');
});

it('lists customer users read-only with their companies, for admins only', function () {
    $admin = customerAdminStaff(['admin']);
    $company = Company::factory()->create(['name' => 'Acme Wholesale']);
    $owner = companyMember($company, 'owner');

    $this->actingAs($admin)->get('/admin/customer-users')->assertOk()->assertSee($owner->email);
    $this->get('/admin/customer-users/'.$owner->id)->assertOk()->assertSee('Acme Wholesale')->assertSee('owner');
    expect(CustomerUserResource::hasPage('create'))->toBeFalse()
        ->and(CustomerUserResource::hasPage('edit'))->toBeFalse();

    $actors = [];
    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $actors[] = customerAdminStaff([$role]);
    }
    foreach ($actors as $actor) {
        $this->actingAs($actor)->get('/admin/customer-users')->assertForbidden();
        $this->get('/admin/customer-users/'.$owner->id)->assertForbidden();
    }
});

it('denies suspension and reinstatement to non-admin staff, customers and inactive admins', function () {
    $company = Company::factory()->create();
    companyMember($company, 'owner');
    $buyer = companyMember($company);
    $suspended = companyMember($company, 'buyer', 'suspended');
    $service = app(CustomerSuspensionService::class);

    $actors = [companyMember($company, 'owner'), User::factory()->create(), customerAdminStaff(['admin'], 'suspended')];
    foreach (['accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $role) {
        $actors[] = customerAdminStaff([$role]);
    }

    foreach ($actors as $actor) {
        expect($actor->can('suspendCustomer', $buyer))->toBeFalse();
        expect(fn () => $service->suspend($buyer, $actor))->toThrow(AuthorizationException::class);
        expect(fn () => $service->reinstate($suspended, $actor))->toThrow(AuthorizationException::class);
    }

    expect($buyer->fresh()->status)->toBe('active')
        ->and($suspended->fresh()->status)->toBe('suspended')
        ->and(AuditLog::query()->whereIn('action', ['auth.customer_suspended', 'auth.customer_reinstated'])->exists())->toBeFalse();
});

it('only suspends active customers and only reinstates suspended ones', function () {
    $admin = customerAdminStaff(['admin']);
    $service = app(CustomerSuspensionService::class);
    $active = User::factory()->create();
    $closed = User::factory()->create(['status' => 'closed']);
    $suspended = User::factory()->create(['status' => 'suspended']);

    expect(fn () => $service->suspend($closed, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->suspend($suspended, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reinstate($active, $admin))->toThrow(AuthorizationException::class);
    expect(fn () => $service->reinstate($closed, $admin))->toThrow(AuthorizationException::class);

    expect($active->fresh()->status)->toBe('active')
        ->and($closed->fresh()->status)->toBe('closed')
        ->and(AuditLog::query()->whereIn('action', ['auth.customer_suspended', 'auth.customer_reinstated'])->exists())->toBeFalse();
});

it('keeps staff out of the customer resource and service, even staff who belong to a company', function () {
    $admin = customerAdminStaff(['admin']);
    $company = Company::factory()->create();
    companyMember($company, 'owner');
    $rep = customerAdminStaff(['rep']);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $rep->id, 'role' => 'buyer']);
    $customer = companyMember($company);
    $this->actingAs($admin);

    Livewire::test(ListCustomerUsers::class)
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$rep, $admin]);
    $this->get('/admin/customer-users/'.$rep->id)->assertNotFound();
    $this->get('/admin/customer-users/'.$admin->id)->assertNotFound();

    expect($admin->can('suspendCustomer', $rep))->toBeFalse();
    expect(fn () => app(CustomerSuspensionService::class)->suspend($rep, $admin))->toThrow(AuthorizationException::class);
    expect($rep->fresh()->status)->toBe('active');
});

it('refuses to suspend the last active owner of a company', function () {
    $admin = customerAdminStaff(['admin']);
    $service = app(CustomerSuspensionService::class);
    $solo = Company::factory()->create(['name' => 'Solo Traders']);
    $soleOwner = companyMember($solo, 'owner');
    companyMember($solo, 'buyer');
    companyMember($solo, 'owner', 'suspended');
    companyMember($solo, 'owner', 'closed');
    $this->actingAs($admin);

    Livewire::test(ViewCustomerUser::class, ['record' => $soleOwner->id])
        ->callAction('suspend')
        ->assertNotified('This user is the last active owner of Solo Traders. Make another user an owner first.');

    expect($soleOwner->fresh()->status)->toBe('active')
        ->and(customerStatusAudit($soleOwner))->toBeEmpty();

    $shared = Company::factory()->create(['name' => 'Shared Supplies']);
    $first = companyMember($shared, 'owner');
    $second = companyMember($shared, 'owner');
    CompanyUser::create(['company_id' => $solo->id, 'user_id' => $first->id, 'role' => 'buyer']);

    $service->suspend($first, $admin);
    expect(fn () => $service->suspend($second, $admin))
        ->toThrow(ValidationException::class, 'This user is the last active owner of Shared Supplies.');

    $multi = companyMember($shared, 'owner');
    CompanyUser::create(['company_id' => Company::factory()->create(['name' => 'Alone Ltd'])->id, 'user_id' => $multi->id, 'role' => 'owner']);
    expect(fn () => $service->suspend($multi, $admin))
        ->toThrow(ValidationException::class, 'This user is the last active owner of Alone Ltd.');

    expect($second->fresh()->status)->toBe('active')
        ->and($multi->fresh()->status)->toBe('active');
});

it('applies the last-owner guard to suspended companies too', function () {
    $admin = customerAdminStaff(['admin']);
    $company = Company::factory()->create(['name' => 'On Stop Ltd', 'status' => 'suspended']);
    $soleOwner = companyMember($company, 'owner');

    expect(fn () => app(CustomerSuspensionService::class)->suspend($soleOwner, $admin))
        ->toThrow(ValidationException::class, 'This user is the last active owner of On Stop Ltd.');
    expect($soleOwner->fresh()->status)->toBe('active');
});

it('lets the sole owner of an applied, rejected or closed company be suspended', function (string $companyStatus) {
    $admin = customerAdminStaff(['admin']);
    $company = Company::factory()->create(['status' => $companyStatus]);
    $soleOwner = companyMember($company, 'owner');

    app(CustomerSuspensionService::class)->suspend($soleOwner, $admin);

    expect($soleOwner->fresh()->status)->toBe('suspended')
        ->and($company->fresh()->status)->toBe($companyStatus)
        ->and(customerStatusAudit($soleOwner))->toHaveCount(1);
})->with(['applied', 'rejected', 'closed']);

it('still guards an approved company when the owner also solely owns an exempt one', function () {
    $admin = customerAdminStaff(['admin']);
    $owner = companyMember(Company::factory()->create(['status' => 'rejected']), 'owner');
    CompanyUser::create(['company_id' => Company::factory()->create(['name' => 'Live Trading'])->id, 'user_id' => $owner->id, 'role' => 'owner']);

    expect(fn () => app(CustomerSuspensionService::class)->suspend($owner, $admin))
        ->toThrow(ValidationException::class, 'This user is the last active owner of Live Trading.');
});

it('re-reads the actor, subject and fellow owners under lock rather than trusting stale models', function () {
    $first = customerAdminStaff(['admin']);
    $second = customerAdminStaff(['admin']);
    $service = app(CustomerSuspensionService::class);
    $company = Company::factory()->create();
    $ownerA = companyMember($company, 'owner');
    $ownerB = companyMember($company, 'owner');
    $buyer = companyMember($company);

    $staleOwnerB = User::query()->findOrFail($ownerB->id);
    $staleBuyer = User::query()->findOrFail($buyer->id);
    $staleSecond = User::query()->findOrFail($second->id);

    $service->suspend($ownerA, $first);
    expect(fn () => $service->suspend($staleOwnerB, $first))->toThrow(ValidationException::class);

    $service->suspend($buyer, $first);
    expect(fn () => $service->suspend($staleBuyer, $first))->toThrow(AuthorizationException::class);

    app(StaffSuspensionService::class)->suspend($second, $first);
    expect(fn () => $service->reinstate($buyer, $staleSecond))->toThrow(AuthorizationException::class);

    expect($ownerB->fresh()->status)->toBe('active')
        ->and($buyer->fresh()->status)->toBe('suspended')
        ->and(customerStatusAudit($buyer))->toHaveCount(1)
        ->and(customerStatusAudit($ownerB))->toBeEmpty();
});

it('rolls back the suspension, session and token removal and audit row together', function () {
    $admin = customerAdminStaff(['admin']);
    $customer = User::factory()->create();
    openCustomerSession($customer);
    Password::broker()->getRepository()->create($customer);

    try {
        DB::transaction(function () use ($customer, $admin): void {
            app(CustomerSuspensionService::class)->suspend($customer, $admin);
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated failure');
    }

    expect($customer->fresh()->status)->toBe('active')
        ->and(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $customer->email)->exists())->toBeTrue()
        ->and(customerStatusAudit($customer))->toBeEmpty();
});

it('prohibits self-suspension in the service, even if the policy is bypassed', function () {
    $admin = customerAdminStaff(['admin']);

    Gate::before(fn () => true);
    expect(fn () => app(CustomerSuspensionService::class)->suspend($admin, $admin))->toThrow(AuthorizationException::class);
    expect($admin->fresh()->status)->toBe('active');
});

it('accepts only status transitions and fields approved for customer audit actions', function () {
    $entry = fn (AuditAction $action, array $before, array $after, ?int $companyId = null) => new AuditEntry(
        action: $action,
        actorType: 'user',
        actorUserId: 1,
        companyId: $companyId,
        subjectType: 'user',
        subjectId: 2,
        before: $before,
        after: $after,
    );
    $logger = new AuditLogger;

    foreach ([
        $entry(AuditAction::CustomerSuspended, ['status' => 'pending'], ['status' => 'suspended']),
        $entry(AuditAction::CustomerSuspended, ['status' => 'active'], ['status' => 'closed']),
        $entry(AuditAction::CustomerSuspended, ['status' => 'active'], ['status' => 'suspended', 'company_status' => 'suspended']),
        $entry(AuditAction::CustomerSuspended, ['status' => 'active'], ['status' => 'suspended'], companyId: 3),
        $entry(AuditAction::CustomerReinstated, ['status' => 'active'], ['status' => 'suspended']),
        $entry(AuditAction::CustomerReinstated, [], ['status' => 'active']),
    ] as $invalid) {
        expect(fn () => $logger->record($invalid))->toThrow(InvalidArgumentException::class);
    }

    $logger->record($entry(AuditAction::CustomerSuspended, ['status' => 'active'], ['status' => 'suspended']));
    $logger->record($entry(AuditAction::CustomerReinstated, ['status' => 'suspended'], ['status' => 'active']));
    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['auth.customer_suspended', 'auth.customer_reinstated']);
});
