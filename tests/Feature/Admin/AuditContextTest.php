<?php

use App\Domain\Accounts\ApplicationReviewService;
use App\Domain\Accounts\TermsKind;
use App\Domain\Accounts\TermsPublisher;
use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\CustomerSuspensionService;
use App\Domain\Identity\StaffOnboardingService;
use App\Domain\Identity\StaffRoleService;
use App\Domain\Identity\StaffSuspensionService;
use App\Domain\Identity\StaffTwoFactorResetService;
use App\Models\AuditLog;
use App\Models\B2bApplication;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
});

function contextStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::query()->where('code', $role)->value('id'), 'user_id' => $user->id]);

    return $user;
}

it('records the client IP and user agent on every staff action audited so far (07 §6.5)', function () {
    Queue::fake();
    $this->app->instance(AuditContext::class, new AuditContext('203.0.113.50', 'StaffBrowser/2.0'));
    $admin = contextStaff('admin');
    contextStaff('admin');

    app(StaffOnboardingService::class)->create(['first_name' => 'New', 'last_name' => 'Starter', 'email' => 'new.starter@example.com', 'role_codes' => ['warehouse']], $admin);
    $staff = contextStaff('warehouse');
    app(StaffRoleService::class)->grant($staff, 'rep', $admin);
    app(StaffTwoFactorResetService::class)->reset($staff, $admin);
    app(StaffSuspensionService::class)->suspend($staff, $admin);
    app(CustomerSuspensionService::class)->suspend(User::factory()->create(), $admin);
    $applicant = User::factory()->create();
    $application = B2bApplication::factory()->create(['applicant_user_id' => $applicant->id, 'contact_email' => $applicant->email, 'vat_number' => 'GB980780684']);
    app(ApplicationReviewService::class)->startReview($application, $admin);
    app(ApplicationReviewService::class)->requestChecks($application, $admin);
    app(TermsPublisher::class)->publish($admin, TermsKind::Trade, 'v1', 'Terms.');

    $actions = AuditLog::query()->orderBy('id')->get();
    expect($actions->pluck('action')->all())->toEqualCanonicalizing([
        'auth.staff_created', 'permission.staff_role_granted', 'auth.staff_onboarding_requested',
        'permission.staff_role_granted', 'auth.staff_two_factor_reset',
        'auth.staff_suspended', 'auth.customer_suspended', 'application.review_started', 'application.verification_requested',
        'configuration.terms_version_published',
    ]);
    foreach ($actions as $entry) {
        expect([$entry->action, $entry->ip, $entry->user_agent])->toBe([$entry->action, '203.0.113.50', 'StaffBrowser/2.0']);
    }
});

it('keeps the IP an entry brings with it, and adds none outside a request', function () {
    config(['audit.identifier_key' => str_repeat('k', 32), 'audit.identifier_key_version' => 'v1']);
    $this->app->instance(AuditContext::class, new AuditContext('203.0.113.50', 'StaffBrowser/2.0'));
    app(AuditLogger::class)->failedSignIn('someone@example.com', '198.51.100.9', 'Attacker/1.0');
    expect(AuditLog::query()->sole()->only(['ip', 'user_agent']))->toBe(['ip' => '198.51.100.9', 'user_agent' => 'Attacker/1.0']);

    $this->app->instance(AuditContext::class, AuditContext::none());
    app(StaffRoleService::class)->grant(contextStaff('warehouse'), 'rep', contextStaff('admin'));
    expect(AuditLog::query()->where('action', 'permission.staff_role_granted')->sole()->only(['ip', 'user_agent']))->toBe(['ip' => null, 'user_agent' => null]);
});

it('takes the client address from the request, through trusted proxies only', function () {
    $request = Request::create('/admin', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_USER_AGENT' => str_repeat('x', 1500)]);

    expect(AuditContext::fromRequest($request)->ip)->toBe('10.0.0.1');

    Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
    try {
        $context = AuditContext::fromRequest($request);
        expect($context->ip)->toBe('203.0.113.9')
            ->and(mb_strlen((string) $context->userAgent))->toBe(1000);
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});
