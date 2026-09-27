<?php

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\CustomerUserResource;
use App\Filament\Resources\CustomerUserResource\Pages\ListCustomerUsers;
use App\Filament\Resources\TermsVersionResource\Pages\PublishTermsVersion;
use App\Filament\Resources\TradeApplicationResource;
use App\Filament\Support\AuditSubjectLabel;
use App\Models\AuditLog;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\TermsVersion;
use App\Models\User;
use Filament\Forms\Components\MarkdownEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Role::factory()->create(['code' => 'admin', 'name' => 'Admin']);
    $this->admin = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::query()->where('code', 'admin')->value('id'), 'user_id' => $this->admin->id]);
    $this->actingAs($this->admin);
});

it('labels the terms button Publish and offers no image or table buttons', function () {
    $page = Livewire::test(PublishTermsVersion::class)->instance();

    $action = (new ReflectionMethod($page, 'getCreateFormAction'))->invoke($page);
    expect($action->getLabel())->toBe('Publish');

    $editor = collect($page->getForm('form')->getFlatComponents())->first(fn ($c) => $c instanceof MarkdownEditor);
    expect($editor)->not->toBeNull()
        ->and($editor->getToolbarButtons())->not->toContain('attachFiles')
        ->and($editor->getToolbarButtons())->not->toContain('table')
        ->and($editor->getToolbarButtons())->toContain('bold');
});

it('calls a customer with an open application a pending trade applicant, and links to their applications', function () {
    $pending = User::factory()->create();
    $application = B2bApplication::factory()->create(['applicant_user_id' => $pending->id, 'contact_email' => $pending->email, 'status' => 'info_requested', 'company_name' => 'Pending Traders']);
    $public = User::factory()->create();
    B2bApplication::factory()->rejected()->create(['applicant_user_id' => $public->id, 'contact_email' => $public->email]);
    $member = User::factory()->create();
    CompanyUser::create(['company_id' => Company::factory()->create(['name' => 'Member Co'])->id, 'user_id' => $member->id, 'role' => 'buyer']);

    Livewire::test(ListCustomerUsers::class)
        ->assertTableColumnStateSet('account', [CustomerUserResource::PENDING_APPLICANT], $pending)
        ->assertTableColumnStateSet('account', ['Public customer'], $public)
        ->assertTableColumnStateSet('account', ['Member Co'], $member);

    $this->get('/admin/customer-users/'.$pending->id)->assertOk()
        ->assertSee('No company — trade applicant — pending')
        ->assertSee('Pending Traders')
        ->assertSee(TradeApplicationResource::getUrl('view', ['record' => $application]), false);
    $this->get('/admin/customer-users/'.$public->id)->assertOk()->assertSee('No company — public customer');
});

it('shows a readable subject in the audit log', function () {
    $company = Company::factory()->create(['name' => 'Readable Ltd', 'account_code' => 'ACC-READ']);
    $applicant = User::factory()->create(['email' => 'subject@example.com']);
    $application = B2bApplication::factory()->create(['applicant_user_id' => $applicant->id, 'contact_email' => $applicant->email, 'company_name' => 'Applied Ltd']);
    $terms = TermsVersion::factory()->create(['version' => '2026-10']);

    expect(AuditSubjectLabel::display('user', $applicant->id))->toBe("user #{$applicant->id} — subject@example.com")
        ->and(AuditSubjectLabel::display('company', $company->id))->toBe("company #{$company->id} — Readable Ltd (ACC-READ)")
        ->and(AuditSubjectLabel::display('b2b_application', $application->id))->toBe("b2b_application #{$application->id} — Application: Applied Ltd")
        ->and(AuditSubjectLabel::display('terms_version', $terms->id))->toBe("terms_version #{$terms->id} — Terms of trade 2026-10")
        ->and(AuditSubjectLabel::display('user', 999999))->toBe('user #999999')
        ->and(AuditSubjectLabel::display(null, null))->toBeNull();

    app(AuditLogger::class)->record(new AuditEntry(
        action: AuditAction::CustomerSuspended, actorType: 'user', actorUserId: $this->admin->id,
        subjectType: 'user', subjectId: $applicant->id, before: ['status' => 'active'], after: ['status' => 'suspended'],
    ));
    $row = AuditLog::query()->sole();

    Livewire::test(ListAuditLogs::class)
        ->assertTableColumnStateSet('subject_display', "user #{$applicant->id} — subject@example.com", $row);
});
