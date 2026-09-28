<?php

use App\Domain\Identity\CompanyMemberRole;
use App\Domain\Identity\CompanyMemberService;
use App\Domain\Identity\CompanyMemberSettings;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function memberOf(Company $company, string $role = 'buyer', string $status = 'active'): User
{
    $user = User::factory()->create(['status' => $status]);
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function membershipOf(Company $company, User $user): CompanyUser
{
    return CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->sole();
}

it('lets an owner change a member\'s role, order limit in pounds and approval, and audits it', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, 'owner');
    $buyer = memberOf($company);

    $this->actingAs($owner)->patch("/account/companies/{$company->public_id}/members/{$buyer->public_id}", [
        'role' => 'approver', 'order_limit' => '2500.5', 'requires_approval' => true,
    ])->assertRedirect(route('account.team'))->assertSessionHasNoErrors();

    $membership = membershipOf($company, $buyer);
    expect($membership->role)->toBe('approver')
        ->and($membership->order_limit_minor)->toBe(250050)
        ->and($membership->requires_approval)->toBeTrue();

    $audit = AuditLog::query()->where('action', 'permission.company_member_changed')->sole();
    expect($audit->actor_user_id)->toBe($owner->id)
        ->and($audit->subject_id)->toBe($buyer->id)
        ->and($audit->before)->toEqual(['role' => 'buyer', 'order_limit_minor' => null, 'requires_approval' => false])
        ->and($audit->after)->toEqual(['role' => 'approver', 'order_limit_minor' => 250050, 'requires_approval' => true]);
});

it('shows owners the team page with limits in pounds, and hides it from buyers', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, 'owner');
    $buyer = memberOf($company);
    CompanyUser::query()->where('company_id', $company->id)->where('user_id', $buyer->id)->update(['order_limit_minor' => 100050]);

    $this->actingAs($owner)->get('/account/team')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Account/Team', false)
        ->where('management.members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === $buyer->email && $m['order_limit'] === '1000.50')));
    $this->actingAs($owner)->get('/account/team')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('management.members', fn ($members) => collect($members)->firstWhere('email', $owner->email)['is_last_owner'] === true
            && collect($members)->firstWhere('email', $buyer->email)['is_last_owner'] === false));
    $this->actingAs($owner)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('team', ['members' => 2, 'pending_invitations' => 0])
        ->where('auth.can_manage_team', true));

    $this->actingAs($buyer)->get('/account/team')->assertForbidden();
    $this->actingAs($buyer)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('team', null)
        ->where('auth.can_manage_team', false));
});

it('lets an owner remove a member, and audits it', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, 'owner');
    $buyer = memberOf($company);

    $this->actingAs($owner)->delete("/account/companies/{$company->public_id}/members/{$buyer->public_id}")->assertRedirect();

    expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $buyer->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($buyer->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'permission.company_member_removed')->sole()->before)
        ->toEqual(['role' => 'buyer', 'order_limit_minor' => null, 'requires_approval' => false]);
});

it('keeps the last active owner of a trading company', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, 'owner');
    memberOf($company, 'owner', 'suspended');
    $service = app(CompanyMemberService::class);

    expect(fn () => $service->update($company, $owner, $owner, new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false)))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->remove($company, $owner, $owner))->toThrow(ValidationException::class);

    expect(membershipOf($company, $owner)->role)->toBe('owner')
        ->and(AuditLog::query()->where('action', 'like', 'permission.company_member_%')->exists())->toBeFalse();

    // With a second active owner, the first may step down.
    $second = memberOf($company, 'owner');
    $service->update($company, $owner, $second, new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false));
    expect(membershipOf($company, $owner)->role)->toBe('buyer');
});

it('does not apply the last-owner rule to a closed company', function () {
    $company = Company::factory()->create(['status' => 'closed']);
    $owner = memberOf($company, 'owner');
    $admin = User::factory()->withTwoFactor()->create();
    $adminRole = Role::factory()->create(['code' => 'admin']);
    RoleUser::create(['role_id' => $adminRole->id, 'user_id' => $admin->id]);

    app(CompanyMemberService::class)->remove($company, $owner, $admin);

    expect(CompanyUser::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

it('refuses member changes from buyers, owners of other companies and non-admin staff', function () {
    $company = Company::factory()->create();
    memberOf($company, 'owner');
    $buyer = memberOf($company);
    $target = memberOf($company);
    $otherOwner = memberOf(Company::factory()->create(), 'owner');
    $warehouse = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::factory()->create(['code' => 'warehouse'])->id, 'user_id' => $warehouse->id]);

    foreach ([$buyer, $otherOwner, $warehouse] as $actor) {
        $this->actingAs($actor)->patch("/account/companies/{$company->public_id}/members/{$target->public_id}", [
            'role' => 'owner', 'order_limit' => '', 'requires_approval' => false,
        ])->assertForbidden();
        $this->actingAs($actor)->delete("/account/companies/{$company->public_id}/members/{$target->public_id}")->assertForbidden();
    }

    expect(membershipOf($company, $target)->role)->toBe('buyer');
});

it('converts pounds to pence with integer arithmetic only', function () {
    expect(CompanyMemberSettings::poundsToMinor('0'))->toBe(0)
        ->and(CompanyMemberSettings::poundsToMinor('2300'))->toBe(230000)
        ->and(CompanyMemberSettings::poundsToMinor('2300.5'))->toBe(230050)
        ->and(CompanyMemberSettings::poundsToMinor('2300.05'))->toBe(230005)
        ->and(CompanyMemberSettings::minorToPounds(230005))->toBe('2300.05')
        ->and(CompanyMemberSettings::minorToPounds(null))->toBeNull();

    expect(fn () => CompanyMemberSettings::from(['role' => 'buyer', 'order_limit' => '1.234', 'requires_approval' => false]))
        ->toThrow(ValidationException::class);
});

it('returns not found for a user who is not a member of the company', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, 'owner');
    $outsider = memberOf(Company::factory()->create());

    $this->actingAs($owner)->patch("/account/companies/{$company->public_id}/members/{$outsider->public_id}", [
        'role' => 'viewer', 'order_limit' => '', 'requires_approval' => false,
    ])->assertNotFound();
    $this->actingAs($owner)->delete("/account/companies/{$company->public_id}/members/{$outsider->public_id}")->assertNotFound();

    expect(membershipOf(Company::query()->whereKeyNot($company->id)->sole(), $outsider)->role)->toBe('buyer');
});
