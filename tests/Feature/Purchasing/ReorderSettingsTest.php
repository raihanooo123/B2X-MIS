<?php

use App\Domain\Purchasing\ReorderSettingsService;
use App\Domain\Purchasing\ReorderSuggestionService;
use App\Filament\Resources\ReorderSettingResource\Pages\ListReorderSettings;
use App\Filament\Resources\ReorderSuggestionResource\Pages\ListReorderSuggestions;
use App\Models\Batch;
use App\Models\Location;
use App\Models\ReorderSetting;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->location = Location::factory()->default()->create();
});

function reorderSettingsStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

function reorderSettingFor(Sku $sku, Location $location): ReorderSetting
{
    return app(ReorderSettingsService::class)->query()->where('sku_id', $sku->id)->where('location_id', $location->id)->sole();
}

it('sets the levels on the NULL-batch row without touching quantities or the ledger', function () {
    $sku = Sku::factory()->create();
    StockLevel::factory()->for($sku)->for($this->location)->create(['on_hand_base_qty' => 40, 'incoming_base_qty' => 6]);

    app(ReorderSettingsService::class)->set($sku->id, $this->location->id, 50, 24);

    $level = StockLevel::identity($sku->id, $this->location->id)->sole();
    expect($level->reorder_point_base_qty)->toBe(50)
        ->and($level->reorder_qty_base_qty)->toBe(24)
        ->and($level->on_hand_base_qty)->toBe(40)
        ->and($level->incoming_base_qty)->toBe(6)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(app(ReorderSuggestionService::class)->query()->where('sku_id', $sku->id)->exists())->toBeTrue();
});

it('creates a zero NULL-batch row for a batch SKU and zeroes the batch rows, so a lower point takes effect', function () {
    $sku = Sku::factory()->batchTracked()->create();
    StockLevel::factory()->for($sku)->for($this->location)->forBatch(Batch::factory()->for($sku)->create())
        ->reorderable(500, 100)->create(['on_hand_base_qty' => 30]);

    app(ReorderSettingsService::class)->set($sku->id, $this->location->id, 20, 12);

    $null = StockLevel::identity($sku->id, $this->location->id)->sole();
    $batchRow = StockLevel::query()->where('sku_id', $sku->id)->whereNotNull('batch_id')->sole();
    expect($null->on_hand_base_qty)->toBe(0)
        ->and($null->allocated_base_qty)->toBe(0)
        ->and($null->reorder_point_base_qty)->toBe(20)
        ->and($batchRow->reorder_point_base_qty)->toBe(0)
        ->and($batchRow->reorder_qty_base_qty)->toBe(0)
        ->and($batchRow->on_hand_base_qty)->toBe(30);

    // §10.1 folds MAX across rows: 30 available > 20, so no suggestion now.
    expect(reorderSettingFor($sku, $this->location)->reorder_point_base_qty)->toBe(20)
        ->and(app(ReorderSuggestionService::class)->query()->where('sku_id', $sku->id)->exists())->toBeFalse();
});

it('opts a SKU out with a reorder point of 0', function () {
    $sku = Sku::factory()->create();
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(50, 24)->create(['on_hand_base_qty' => 10]);

    app(ReorderSettingsService::class)->set($sku->id, $this->location->id, 0, 0);

    expect(app(ReorderSuggestionService::class)->query()->where('sku_id', $sku->id)->exists())->toBeFalse();
});

it('refuses negative values, inactive or untracked SKUs and non-sellable locations, writing nothing', function (Closure $call) {
    expect(fn () => $call($this->location))->toThrow(ValidationException::class)
        ->and(StockLevel::query()->count())->toBe(0);
})->with([
    'negative point' => [fn (Location $l) => app(ReorderSettingsService::class)->set(Sku::factory()->create()->id, $l->id, -1, 0)],
    'negative qty' => [fn (Location $l) => app(ReorderSettingsService::class)->set(Sku::factory()->create()->id, $l->id, 1, -5)],
    'inactive SKU' => [fn (Location $l) => app(ReorderSettingsService::class)->set(Sku::factory()->create(['status' => 'discontinued'])->id, $l->id, 5, 5)],
    'untracked SKU' => [fn (Location $l) => app(ReorderSettingsService::class)->set(Sku::factory()->create(['is_stock_tracked' => false])->id, $l->id, 5, 5)],
    'non-sellable location' => [fn (Location $l) => app(ReorderSettingsService::class)->set(Sku::factory()->create()->id, Location::factory()->create(['is_sellable' => false])->id, 5, 5)],
]);

it('lists every active tracked SKU per sellable location and edits through Filament', function () {
    $sku = Sku::factory()->create();
    $other = Sku::factory()->create(['status' => 'discontinued']);
    $this->actingAs(reorderSettingsStaff('purchasing'));

    $this->get('/admin/reorder-settings')->assertOk()->assertSee('Reorder settings');
    $row = reorderSettingFor($sku, $this->location);
    Livewire::test(ListReorderSettings::class)
        ->assertCanSeeTableRecords([$row])
        ->assertTableColumnFormattedStateSet('reorder_point_base_qty', 'Not set', $row)
        ->callTableAction('editReorderLevels', $row, ['reorder_point_base_qty' => 30, 'reorder_qty_base_qty' => 48])
        ->assertHasNoTableActionErrors();

    expect(StockLevel::identity($sku->id, $this->location->id)->sole()->reorder_point_base_qty)->toBe(30)
        ->and(app(ReorderSettingsService::class)->query()->where('sku_id', $other->id)->exists())->toBeFalse();
});

it('offers the same edit on a reorder suggestion row', function () {
    $sku = Sku::factory()->create();
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(50, 24)->create(['on_hand_base_qty' => 10]);
    $this->actingAs(reorderSettingsStaff('purchasing'));

    $suggestion = app(ReorderSuggestionService::class)->query()->where('sku_id', $sku->id)->sole();
    Livewire::test(ListReorderSuggestions::class)
        ->callTableAction('editReorderLevels', $suggestion, ['reorder_point_base_qty' => 5, 'reorder_qty_base_qty' => 24])
        ->assertHasNoTableActionErrors();

    expect(app(ReorderSuggestionService::class)->query()->where('sku_id', $sku->id)->exists())->toBeFalse();
});

it('keeps reorder settings to admin and purchasing', function () {
    foreach (['admin', 'purchasing'] as $role) {
        $this->actingAs(reorderSettingsStaff($role))->get('/admin/reorder-settings')->assertOk();
    }

    foreach (['warehouse', 'accounts', 'rep', 'sales_manager'] as $role) {
        $user = reorderSettingsStaff($role);
        $this->actingAs($user)->get('/admin/reorder-settings')->assertForbidden();
        expect($user->can('update', ReorderSetting::class))->toBeFalse();
    }

    expect(reorderSettingsStaff('purchasing')->can('delete', new ReorderSetting))->toBeFalse();
});
