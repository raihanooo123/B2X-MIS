<?php

use App\Domain\Identity\CompanyInvitationService;
use App\Domain\Identity\CompanyMemberRole;
use App\Domain\Identity\CompanyMemberSettings;
use App\Domain\Notifications\Notices\CompanyInvitationAccepted;
use App\Domain\Notifications\Notices\CompanyInvited;
use App\Jobs\SendNotification;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
});

function inviteOwner(Company $company): User
{
    $owner = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);

    return $owner;
}

/** @return array<string, mixed> */
function invitationInput(array $changes = []): array
{
    return array_replace([
        'email' => 'new.buyer@example.com',
        'first_name' => 'Sam',
        'last_name' => 'Patel',
        'role' => 'buyer',
        'order_limit' => '2500.50',
        'requires_approval' => false,
    ], $changes);
}

function invitationToken(CompanyInvitation $invitation): string
{
    $job = Queue::pushed(SendNotification::class, fn (SendNotification $job) => $job->notice instanceof CompanyInvited
        && $job->notice->invitationId === $invitation->id)->last();
    expect($job)->not->toBeNull();

    return Crypt::decryptString($job->notice->encryptedToken);
}

it('lets an owner invite from the account page, storing only a hash and creating nothing else', function () {
    $company = Company::factory()->create();
    $owner = inviteOwner($company);

    $this->actingAs($owner)->post("/account/companies/{$company->public_id}/invitations", invitationInput())
        ->assertRedirect()->assertSessionHasNoErrors();

    $invitation = CompanyInvitation::query()->sole();
    $token = invitationToken($invitation);
    expect($invitation->email)->toBe('new.buyer@example.com')
        ->and($invitation->role)->toBe('buyer')
        ->and($invitation->order_limit_minor)->toBe(250050)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and(User::query()->where('email', 'new.buyer@example.com')->exists())->toBeFalse()
        ->and(CompanyUser::query()->where('company_id', $company->id)->count())->toBe(1);

    $audit = AuditLog::query()->where('action', 'permission.company_invited')->sole();
    expect($audit->actor_user_id)->toBe($owner->id)
        ->and($audit->company_id)->toBe($company->id)
        ->and($audit->after)->toEqual(['role' => 'buyer', 'order_limit_minor' => 250050, 'requires_approval' => false]);
});

it('refuses invitations from buyers, other companies\' owners and bad order limits', function () {
    $company = Company::factory()->create();
    $buyer = User::factory()->create();
    CompanyUser::create(['company_id' => $company->id, 'user_id' => $buyer->id, 'role' => 'buyer']);
    $otherOwner = inviteOwner(Company::factory()->create());

    $this->actingAs($buyer)->post("/account/companies/{$company->public_id}/invitations", invitationInput())->assertForbidden();
    $this->actingAs($otherOwner)->post("/account/companies/{$company->public_id}/invitations", invitationInput())->assertForbidden();

    $owner = inviteOwner($company);
    foreach (['12.345', 'abc', '-5', '1,000'] as $bad) {
        $this->actingAs($owner)->post("/account/companies/{$company->public_id}/invitations", invitationInput(['order_limit' => $bad]))
            ->assertSessionHasErrors('order_limit');
    }

    expect(CompanyInvitation::query()->exists())->toBeFalse();
});

it('shows the same invitation page whether or not the address has an account', function () {
    $company = Company::factory()->create(['name' => 'Quay Stores']);
    $owner = inviteOwner($company);
    User::factory()->create(['email' => 'existing@example.com']);
    $service = app(CompanyInvitationService::class);
    $settings = new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false);

    $pages = [];
    foreach (['existing@example.com', 'brand.new@example.com'] as $email) {
        $invitation = $service->invite($company, $owner, $email, 'Alex', 'Jones', $settings);
        $this->get('/company-invitations/'.invitationToken($invitation))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$pages) {
                $page->component('Auth/CompanyInvitation', false);
                $props = $page->toArray()['props'];
                $pages[] = [$props['invitation'], $props['signed_in'], $props['refusal']];
            })
            ->assertDontSee($email);
    }

    expect($pages[0])->toBe($pages[1])
        ->and($pages[0][0])->toBe(['company' => 'Quay Stores', 'role' => 'buyer']);
});

it('creates a new account and membership when a new person accepts, and tells the inviter', function () {
    $company = Company::factory()->create();
    $owner = inviteOwner($company);
    $invitation = app(CompanyInvitationService::class)->invite($company, $owner, 'Sam@Example.com', 'Sam', 'Patel',
        new CompanyMemberSettings(CompanyMemberRole::Approver, 100000, true));
    $token = invitationToken($invitation);

    $this->post("/company-invitations/{$token}", ['password' => 'blue-kettle-river-42', 'password_confirmation' => 'blue-kettle-river-42'])
        ->assertRedirect();

    $user = User::query()->where('email', 'sam@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('blue-kettle-river-42', (string) $user->password_hash))->toBeTrue();

    $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->sole();
    expect($membership->role)->toBe('approver')
        ->and($membership->order_limit_minor)->toBe(100000)
        ->and($membership->requires_approval)->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'permission.company_invitation_accepted')->sole()->actor_user_id)->toBe($user->id);

    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->notice instanceof CompanyInvitationAccepted
        && $job->recipient->email === $owner->email);

    // Single use: the same link now refuses.
    auth()->logout();
    $this->get("/company-invitations/{$token}")->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($refusal) => is_string($refusal)));
});

it('never changes an existing account\'s password: it must sign in to accept', function () {
    $company = Company::factory()->create();
    $owner = inviteOwner($company);
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $originalHash = $existing->password_hash;
    $invitation = app(CompanyInvitationService::class)->invite($company, $owner, 'existing@example.com', 'Ex', 'Isting',
        new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false));
    $token = invitationToken($invitation);

    $this->post("/company-invitations/{$token}", ['password' => 'blue-kettle-river-42', 'password_confirmation' => 'blue-kettle-river-42'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($existing->fresh()->password_hash)->toBe($originalHash)
        ->and(CompanyUser::query()->where('user_id', $existing->id)->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    // Signed in as that address, accepting works.
    $this->actingAs($existing)->post("/company-invitations/{$token}")->assertRedirect();
    expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('refuses an invitation opened while signed in as a different address', function () {
    $company = Company::factory()->create();
    $owner = inviteOwner($company);
    $invitation = app(CompanyInvitationService::class)->invite($company, $owner, 'intended@example.com', 'In', 'Tended',
        new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false));
    $someoneElse = User::factory()->create();

    $this->actingAs($someoneElse)->post('/company-invitations/'.invitationToken($invitation))->assertForbidden();

    expect(CompanyUser::query()->where('user_id', $someoneElse->id)->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('makes only the newest link work after a resend, and none after a revoke', function () {
    $company = Company::factory()->create();
    $owner = inviteOwner($company);
    $service = app(CompanyInvitationService::class);
    $first = $service->invite($company, $owner, 'sam@example.com', 'Sam', 'Patel', new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false));
    $firstToken = invitationToken($first);

    $this->actingAs($owner)->post("/account/invitations/{$first->public_id}/resend")->assertRedirect();
    $second = CompanyInvitation::query()->whereKeyNot($first->id)->sole();
    $secondToken = invitationToken($second);
    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->isOpen())->toBeTrue();

    auth()->logout();
    $this->get("/company-invitations/{$firstToken}")->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($refusal) => is_string($refusal)));

    $this->actingAs($owner)->delete("/account/invitations/{$second->public_id}")->assertRedirect();
    auth()->logout();
    $this->get("/company-invitations/{$secondToken}")->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($refusal) => is_string($refusal)));
    $this->post("/company-invitations/{$secondToken}", ['password' => 'blue-kettle-river-42', 'password_confirmation' => 'blue-kettle-river-42'])->assertForbidden();

    expect(User::query()->where('email', 'sam@example.com')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'permission.company_invitation_revoked')->count())->toBe(2);
});

it('refuses an expired invitation', function () {
    $company = Company::factory()->create();
    $owner = inviteOwner($company);
    $invitation = app(CompanyInvitationService::class)->invite($company, $owner, 'late@example.com', 'La', 'Te',
        new CompanyMemberSettings(CompanyMemberRole::Buyer, null, false));
    $token = invitationToken($invitation);

    $this->travel(8)->days();

    $this->post("/company-invitations/{$token}", ['password' => 'blue-kettle-river-42', 'password_confirmation' => 'blue-kettle-river-42'])->assertForbidden();
    expect(User::query()->where('email', 'late@example.com')->exists())->toBeFalse();
});

it('lists open invitations on the account page of a signed-in invitee with a confirmed email', function () {
    $company = Company::factory()->create(['name' => 'Quay Stores']);
    $owner = inviteOwner($company);
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = app(CompanyInvitationService::class)->invite($company, $owner, 'invitee@example.com', 'In', 'Vitee',
        new CompanyMemberSettings(CompanyMemberRole::Viewer, null, false));

    $this->actingAs($invitee)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Account/Index', false)
        ->where('invitations.0.company', 'Quay Stores')
        ->where('invitations.0.role', 'viewer'));

    $this->post("/account/invitations/{$invitation->public_id}/accept")->assertRedirect();
    expect(CompanyUser::query()->where('company_id', $company->id)->where('user_id', $invitee->id)->value('role'))->toBe('viewer');
});
