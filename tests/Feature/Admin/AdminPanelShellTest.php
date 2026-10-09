<?php

use App\Domain\Storefront\Branding;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SystemConfiguration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** A staff user holding `$roleCode`, with 2FA so the panel lets them in. */
function adminShellStaff(string $roleCode): User
{
    $user = User::factory()->withTwoFactor()->create();
    $role = Role::query()->where('code', $roleCode)->first() ?? Role::factory()->create(['code' => $roleCode]);
    RoleUser::create(['role_id' => $role->id, 'user_id' => $user->id]);

    return $user;
}

it('shows the business name from the storefront settings, not a hard-coded one', function () {
    SystemConfiguration::factory()->create(['config_key' => Branding::NAME, 'value_type' => 'text', 'value_int' => null, 'value_text' => 'Quay Stores']);

    $this->actingAs(adminShellStaff('admin'))->get('/admin')
        ->assertOk()
        ->assertSee('Quay Stores')
        ->assertDontSee('B2X Wholesale');
});

it('opens the dashboard with its overview instead of the Filament info widget', function () {
    $this->actingAs(adminShellStaff('admin'))->get('/admin')
        ->assertOk()
        ->assertSee('Trade receivables')
        ->assertSee('Quick links')
        ->assertDontSee('filament-info-widget', false);
});

it('shows a role only the dashboard figures its policies allow', function () {
    $this->actingAs(adminShellStaff('warehouse'))->get('/admin')
        ->assertOk()
        ->assertDontSee('Trade receivables')
        ->assertDontSee('Trade applications to review');
});

/*
 * Filament throws while rendering the sidebar if a group has an icon and
 * one of its items does too. The groups carry the icons, so a resource or
 * page added to a group with its own $navigationIcon would break every
 * panel page; this catches it before it ships.
 */
it('gives no grouped navigation item an icon of its own, since the groups carry them', function () {
    $panel = Filament::getPanel('admin');

    $offenders = [];
    foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
        if ($class::getNavigationGroup() !== null && $class::getNavigationIcon() !== null) {
            $offenders[] = $class;
        }
    }
    foreach ($panel->getNavigationItems() as $item) {
        if ($item->getGroup() !== null && $item->getIcon() !== null) {
            $offenders[] = $item->getLabel();
        }
    }

    expect($offenders)->toBe([]);
});
