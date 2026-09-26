<?php

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function navigationStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

it('registers the stocktake page and read-only history routes', function () {
    expect(route('warehouse.stocktake', absolute: false))->toBe('/warehouse/stocktake')
        ->and(route('filament.admin.resources.stocktakes.index', absolute: false))->toBe('/admin/stocktakes');
});

it('shows warehouse navigation only when the corresponding policy permits it', function () {
    $panel = Filament::getDefaultPanel();
    $expected = [
        'admin' => ['Goods in', 'Picking', 'Dispatch', 'Stocktake'],
        'warehouse' => ['Goods in', 'Picking', 'Dispatch', 'Stocktake'],
        'purchasing' => ['Goods in', 'Stocktake'],
        'accounts' => ['Stocktake'],
        'rep' => [],
    ];

    foreach ($expected as $role => $labels) {
        $this->actingAs(navigationStaff($role));

        $visible = collect($panel->getNavigationItems())
            ->filter(fn ($item) => $item->getGroup() === 'Warehouse' && $item->isVisible())
            ->map(fn ($item) => $item->getLabel())
            ->values()
            ->all();

        expect($visible)->toBe($labels);
    }
});

it('shares matching staff links with the stocktake page', function () {
    $this->actingAs(navigationStaff('accounts'))
        ->get('/warehouse/stocktake')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Warehouse/Stocktake', false)
            ->where('auth.staff_navigation.admin', true)
            ->where('auth.staff_navigation.goods_in', false)
            ->where('auth.staff_navigation.picking', false)
            ->where('auth.staff_navigation.dispatch', false)
            ->where('auth.staff_navigation.stocktake', true));
});

it('renders warehouse entries in Filament for an operator', function () {
    $this->actingAs(navigationStaff('warehouse'))
        ->get('/admin')
        ->assertOk()
        ->assertSee('Goods in')
        ->assertSee('href="'.route('warehouse.stocktake').'"', false);
});

it('hides warehouse entries in Filament for a rep', function () {
    $this->actingAs(navigationStaff('rep'))
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('Goods in')
        ->assertDontSee('href="'.route('warehouse.stocktake').'"', false)
        ->assertDontSee('Stocktake history');
});
