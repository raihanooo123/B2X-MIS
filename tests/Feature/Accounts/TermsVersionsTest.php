<?php

use App\Domain\Accounts\TermsKind;
use App\Domain\Accounts\TermsPublisher;
use App\Filament\Resources\TermsVersionResource;
use App\Filament\Resources\TermsVersionResource\Pages\ListTermsVersions;
use App\Filament\Resources\TermsVersionResource\Pages\PublishTermsVersion;
use App\Filament\Resources\TermsVersionResource\Pages\ViewTermsVersion;
use App\Models\AuditLog;
use App\Models\B2bApplication;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Database\Seeders\PlaceholderTermsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    foreach (['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'] as $code) {
        Role::factory()->create(['code' => $code, 'name' => ucfirst($code)]);
    }
});

function termsStaff(string $role = 'admin', string $status = 'active', array $attributes = []): User
{
    $user = User::factory()->withTwoFactor()->create(['status' => $status, ...$attributes]);
    RoleUser::create(['role_id' => Role::query()->where('code', $role)->value('id'), 'user_id' => $user->id]);

    return $user;
}

function termsAcceptanceFor(TermsVersion $terms): TermsAcceptance
{
    $user = User::factory()->create();
    $application = B2bApplication::factory()->create(['applicant_user_id' => $user->id, 'contact_email' => $user->email]);

    return TermsAcceptance::query()->create([
        'terms_version_id' => $terms->id,
        'user_id' => $user->id,
        'b2b_application_id' => $application->id,
        'source' => 'trade_application',
        'ip' => '203.0.113.9',
    ]);
}

it('publishes a version with its SHA-256 and an audit row that never holds the text', function () {
    $admin = termsStaff();
    $body = "# Terms of trade\n\n1. Payment is due on the terms stated on your account.";

    $terms = app(TermsPublisher::class)->publish($admin, TermsKind::Trade, '2026-10', $body);

    expect($terms->body_sha256)->toBe(hash('sha256', $body))
        ->and($terms->published_by_user_id)->toBe($admin->id)
        ->and(TermsVersion::current(TermsKind::Trade)?->id)->toBe($terms->id);

    $audit = AuditLog::query()->where('action', 'configuration.terms_version_published')->sole();
    expect($audit->event_family)->toBe('configuration')
        ->and($audit->subject_type)->toBe('terms_version')
        ->and($audit->subject_id)->toBe($terms->id)
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->before)->toBeNull()
        ->and($audit->after)->toEqual([
            'kind' => 'trade',
            'version' => '2026-10',
            'effective_from' => $terms->effective_from->toIso8601String(),
            'body_sha256' => hash('sha256', $body),
        ])
        ->and(json_encode($audit->after))->not->toContain('Payment is due');
});

it('schedules a future version without replacing the one in force', function () {
    $admin = termsStaff();
    $publisher = app(TermsPublisher::class);
    $now = $publisher->publish($admin, TermsKind::Trade, 'v1', 'Current terms.');
    $later = $publisher->publish($admin, TermsKind::Trade, 'v2', 'Next terms.', now()->addWeek());

    expect(TermsVersion::current(TermsKind::Trade)?->id)->toBe($now->id)
        ->and(TermsVersionResource::state($later))->toBe('Scheduled')
        ->and(TermsVersionResource::state($now))->toBe('In force');

    $this->travel(8)->days();
    expect(TermsVersion::current(TermsKind::Trade)?->id)->toBe($later->id)
        ->and(TermsVersionResource::state($now))->toBe('Superseded');
});

it('refuses past dates, reserved and duplicate labels, empty text, and anyone but an active admin', function () {
    $admin = termsStaff();
    $publisher = app(TermsPublisher::class);
    $publisher->publish($admin, TermsKind::Trade, 'v1', 'Terms.');

    expect(fn () => $publisher->publish($admin, TermsKind::Trade, 'v2', 'Terms.', now()->subHour()))->toThrow(ValidationException::class, 'never in the past');
    expect(fn () => $publisher->publish($admin, TermsKind::Trade, 'placeholder-2', 'Terms.'))->toThrow(ValidationException::class, 'reserved');
    expect(fn () => $publisher->publish($admin, TermsKind::Trade, 'v1', 'Other terms.', now()->addDay()))->toThrow(ValidationException::class, 'already exists');
    expect(fn () => $publisher->publish($admin, TermsKind::Trade, 'bad label!', 'Terms.'))->toThrow(ValidationException::class);
    expect(fn () => $publisher->publish($admin, TermsKind::Trade, 'v3', '   '))->toThrow(ValidationException::class);
    expect(fn () => $publisher->publishPlaceholder($admin, TermsKind::Trade, 'placeholder-9', 'x'))->toThrow(LogicException::class);

    foreach ([termsStaff('accounts'), termsStaff('warehouse'), termsStaff('admin', 'suspended'), User::factory()->create()] as $actor) {
        expect(fn () => $publisher->publish($actor, TermsKind::Trade, 'v9', 'Terms.', now()->addDay()))->toThrow(AuthorizationException::class);
    }

    expect(TermsVersion::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'configuration.terms_version_published')->count())->toBe(1);
});

it('never lets a version be updated or deleted, and never lets an acceptance be updated', function () {
    $terms = TermsVersion::factory()->create();
    $acceptance = termsAcceptanceFor($terms);

    expect(fn () => DB::transaction(fn () => DB::table('terms_versions')->where('id', $terms->id)->update(['body_markdown' => 'Changed.'])))
        ->toThrow(QueryException::class, 'terms_versions is append-only');
    expect(fn () => DB::transaction(fn () => DB::table('terms_versions')->where('id', $terms->id)->delete()))
        ->toThrow(QueryException::class, 'terms_versions is append-only');
    expect(fn () => DB::transaction(fn () => DB::table('terms_acceptances')->where('id', $acceptance->id)->update(['ip' => '198.51.100.1'])))
        ->toThrow(QueryException::class, 'terms_acceptances is append-only');

    expect($terms->fresh()->body_markdown)->not->toBe('Changed.')
        ->and($acceptance->fresh()->ip)->toBe('203.0.113.9');

    // DELETE is allowed only through the application's cascade (02 §25.1).
    B2bApplication::query()->whereKey($acceptance->b2b_application_id)->delete();
    expect(TermsAcceptance::query()->whereKey($acceptance->id)->exists())->toBeFalse();
});

it('allows one acceptance per application, tied to its source', function () {
    $terms = TermsVersion::factory()->create();
    $acceptance = termsAcceptanceFor($terms);

    expect(fn () => DB::transaction(fn () => DB::table('terms_acceptances')->insert([
        'terms_version_id' => $terms->id, 'user_id' => $acceptance->user_id,
        'b2b_application_id' => $acceptance->b2b_application_id, 'source' => 'trade_application',
    ])))->toThrow(QueryException::class, 'terms_acceptances_application_uq');
    expect(fn () => DB::transaction(fn () => DB::table('terms_acceptances')->insert([
        'terms_version_id' => $terms->id, 'user_id' => $acceptance->user_id, 'source' => 'trade_application',
    ])))->toThrow(QueryException::class, 'terms_acceptances_source_subject_chk');
});

it('publishes from Settings → Terms after confirmation, and offers no edit or delete', function () {
    $admin = termsStaff();
    $this->actingAs($admin);

    Livewire::test(PublishTermsVersion::class)
        ->fillForm(['kind' => 'trade', 'version' => '2026-10', 'body_markdown' => '# Terms', 'confirmed' => false])
        ->call('create')
        ->assertHasFormErrors(['confirmed' => 'accepted']);
    expect(TermsVersion::query()->exists())->toBeFalse();

    Livewire::test(PublishTermsVersion::class)
        ->fillForm(['kind' => 'trade', 'version' => '2026-10', 'body_markdown' => '# Terms', 'confirmed' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $terms = TermsVersion::query()->sole();

    Livewire::test(PublishTermsVersion::class)
        ->fillForm(['kind' => 'trade', 'version' => '2026-10', 'body_markdown' => 'Again', 'effective_from' => now()->addDay()->format('Y-m-d H:i'), 'confirmed' => true])
        ->call('create')
        ->assertHasFormErrors(['version']);

    Livewire::test(ListTermsVersions::class)->assertCanSeeTableRecords([$terms]);
    Livewire::test(ViewTermsVersion::class, ['record' => $terms->id])
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
    expect(TermsVersionResource::hasPage('edit'))->toBeFalse();

    $this->get('/admin/terms')->assertOk();
    $this->get('/admin/terms/'.$terms->id)->assertOk()->assertSee($terms->body_sha256);

    foreach (['accounts', 'rep', 'warehouse'] as $role) {
        $this->actingAs(termsStaff($role))->get('/admin/terms')->assertForbidden();
        $this->get('/admin/terms/publish')->assertForbidden();
    }
});

it('seeds placeholder terms locally only, published by the demo admin, once', function () {
    $seeder = fn () => $this->app->make(PlaceholderTermsSeeder::class)->run();

    expect($seeder)->toThrow(RuntimeException::class, 'local environment');
    expect(TermsVersion::query()->exists())->toBeFalse();

    $this->app['env'] = 'local';
    $admin = termsStaff('admin', 'active', ['email' => PlaceholderTermsSeeder::ADMIN_EMAIL]);

    $seeder();
    $seeder();

    $terms = TermsVersion::query()->sole();
    expect($terms->version)->toBe('placeholder-1')
        ->and($terms->kind)->toBe('trade')
        ->and($terms->published_by_user_id)->toBe($admin->id)
        ->and($terms->body_markdown)->toStartWith('# PLACEHOLDER — NOT TERMS OF TRADE')
        ->and(TermsVersion::current(TermsKind::Trade)?->id)->toBe($terms->id);

    // Even locally, the admin screen cannot publish a placeholder label.
    expect(fn () => app(TermsPublisher::class)->publish($admin, TermsKind::Trade, 'placeholder-2', 'x'))->toThrow(ValidationException::class);
});
