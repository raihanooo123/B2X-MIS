<?php

use App\Domain\Purchasing\ReorderSuggestionService;
use App\Filament\Resources\ReorderSuggestionResource\Pages\ListReorderSuggestions;
use App\Models\Batch;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\ReorderSuggestion;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\SystemConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->location = Location::factory()->default()->create();
});

function reorderStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

function reorderShip(Pack $pack, Location $location, int $qty, int $daysAgo, bool $dispatched = true): void
{
    $shipment = $dispatched
        ? Shipment::factory()->dispatched()->create(['location_id' => $location->id, 'dispatched_at' => now()->subDays($daysAgo)])
        : Shipment::factory()->create(['location_id' => $location->id]);
    ShipmentLine::factory()->create([
        'shipment_id' => $shipment->id,
        'order_line_id' => OrderLine::factory()->forPack($pack, $qty)->create()->id,
        'dispatched_base_qty' => $qty,
    ]);
}

function reorderPo(Sku $sku, Supplier $supplier, int $packBaseUnits, int $orderedDaysAgo, string $status): void
{
    $pack = Pack::factory()->for($sku)->outer($packBaseUnits)->create();
    $po = PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => $status,
        'ordered_at' => now()->subDays($orderedDaysAgo),
    ]);
    PurchaseOrderLine::factory()->for($po)->forPack($pack, 1)->create();
}

function reorderFor(Sku $sku): ?ReorderSuggestion
{
    return app(ReorderSuggestionService::class)->query()->where('sku_id', $sku->id)->first();
}

it('suggests the reorder quantity, rounded to the default sell pack, below the reorder point with no sales or PO', function () {
    $sku = Sku::factory()->create();
    Pack::factory()->for($sku)->create(['code' => 'CASE4', 'label' => 'Case of 4', 'base_units' => 4]);
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(5, 10)->create(['on_hand_base_qty' => 3]);

    $row = reorderFor($sku);

    // position 3 <= reorder point 5; max(reorder_qty 10, shortfall 0) = 10 → case of 4 → 12.
    expect($row)->not->toBeNull()
        ->and($row->below_reorder_point)->toBeTrue()
        ->and($row->cover_short)->toBeFalse()
        ->and($row->supplier_id)->toBeNull()
        ->and($row->lead_time_days)->toBe(ReorderSuggestionService::DEFAULT_LEAD_TIME_DAYS)
        ->and($row->sold_base_qty)->toBe(0)
        ->and($row->cover_days)->toBeNull()
        ->and($row->pack_base_units)->toBe(4)
        ->and($row->suggested_base_qty)->toBe(12);
});

it('leaves out well-stocked, opted-out and non-sellable SKU locations', function () {
    $wellStocked = Sku::factory()->create();
    StockLevel::factory()->for($wellStocked)->for($this->location)->reorderable(5, 10)->create(['on_hand_base_qty' => 100]);
    $optedOut = Sku::factory()->create();
    StockLevel::factory()->for($optedOut)->for($this->location)->create(['on_hand_base_qty' => 0]);
    $quarantineOnly = Sku::factory()->create();
    StockLevel::factory()->for($quarantineOnly)->for(Location::factory()->create(['is_sellable' => false]))
        ->reorderable(5, 10)->create(['on_hand_base_qty' => 0]);

    expect(reorderFor($wellStocked))->toBeNull()
        ->and(reorderFor($optedOut))->toBeNull()
        ->and(reorderFor($quarantineOnly))->toBeNull();
});

it('triggers on cover using dispatched sales, stock on order, the latest real PO and outlier exclusion', function () {
    $sku = Sku::factory()->create();
    $each = Pack::factory()->for($sku)->create();
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(1, 0)
        ->create(['on_hand_base_qty' => 10, 'incoming_base_qty' => 5]);

    $older = Supplier::factory()->create(['lead_time_days' => 60]);
    $latest = Supplier::factory()->create(['lead_time_days' => 10]);
    reorderPo($sku, $older, 12, 60, 'received');
    reorderPo($sku, $latest, 6, 10, 'confirmed');
    reorderPo($sku, Supplier::factory()->create(['lead_time_days' => 1]), 24, 2, 'draft');
    reorderPo($sku, Supplier::factory()->create(['lead_time_days' => 1]), 48, 1, 'cancelled');

    foreach (range(1, 9) as $day) {
        reorderShip($each, $this->location, 10, $day * 5);
    }
    reorderShip($each, $this->location, 400, 3);          // above the 95th percentile (224.5) — excluded
    reorderShip($each, $this->location, 50, 100);         // outside the 90-day window
    reorderShip($each, $this->location, 50, 0, false);    // not dispatched
    reorderShip($each, Location::factory()->create(), 50, 3); // another location's sales

    $row = reorderFor($sku);

    // sold 90 over 90 days; position 10 + 5 = 15; lead 10 + safety 7:
    //   15 × 90 = 1350 <= 90 × 17 = 1530 → cover short; 15 > reorder point 1.
    // forecast ceil(90 × (10 + 14) / 90) = 24; shortfall 24 − 15 = 9 → packs of 6 → 12.
    expect($row)->not->toBeNull()
        ->and($row->supplier_id)->toBe($latest->id)
        ->and($row->lead_time_days)->toBe(10)
        ->and($row->sold_base_qty)->toBe(90)
        ->and($row->position_base_qty)->toBe(15)
        ->and($row->cover_days)->toBe(15)
        ->and($row->below_reorder_point)->toBeFalse()
        ->and($row->cover_short)->toBeTrue()
        ->and($row->pack_base_units)->toBe(6)
        ->and($row->suggested_base_qty)->toBe(12);
});

it('folds a batch-tracked SKU across its batch rows and the incoming NULL-batch row', function () {
    $sku = Sku::factory()->batchTracked()->create();
    foreach ([3, 4] as $qty) {
        StockLevel::factory()->for($sku)->for($this->location)->forBatch(Batch::factory()->for($sku)->create())
            ->create(['on_hand_base_qty' => $qty]);
    }
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(10, 7)
        ->create(['on_hand_base_qty' => 0, 'incoming_base_qty' => 2]);

    $row = reorderFor($sku);

    expect($row->available_base_qty)->toBe(7)
        ->and($row->incoming_base_qty)->toBe(2)
        ->and($row->position_base_qty)->toBe(9)
        ->and($row->reorder_point_base_qty)->toBe(10)
        ->and($row->suggested_base_qty)->toBe(7);
});

it('resolves settings location first, then global', function () {
    $sku = Sku::factory()->create();
    $each = Pack::factory()->for($sku)->create();
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(1, 0)->create(['on_hand_base_qty' => 20]);
    reorderShip($each, $this->location, 90, 10);

    // sold 90 (1/day), position 20, no PO. Global lead 50: 1800 <= 90 × 57 → suggested.
    SystemConfiguration::factory()->create(['config_key' => ReorderSuggestionService::DEFAULT_LEAD_TIME_DAYS_KEY, 'value_int' => 50]);
    expect(reorderFor($sku)?->lead_time_days)->toBe(50);

    // Location lead 5 wins: 1800 > 90 × 12 = 1080 → not suggested.
    SystemConfiguration::factory()->create([
        'config_key' => ReorderSuggestionService::DEFAULT_LEAD_TIME_DAYS_KEY,
        'scope' => 'location',
        'location_id' => $this->location->id,
        'value_int' => 5,
    ]);
    expect(reorderFor($sku))->toBeNull();
});

it('renders read-only for admin and purchasing only', function () {
    $sku = Sku::factory()->create();
    StockLevel::factory()->for($sku)->for($this->location)->reorderable(5, 10)->create(['on_hand_base_qty' => 0]);

    foreach (['admin', 'purchasing'] as $role) {
        $this->actingAs(reorderStaff($role))->get('/admin/reorder-suggestions')->assertOk()->assertSee('Reorder suggestions');
    }
    Livewire::test(ListReorderSuggestions::class)
        ->assertCanSeeTableRecords([reorderFor($sku)])
        ->assertTableColumnStateSet('suggested_base_qty', 10, reorderFor($sku));

    foreach (['warehouse', 'accounts', 'rep', 'sales_manager'] as $role) {
        $this->actingAs(reorderStaff($role))->get('/admin/reorder-suggestions')->assertForbidden();
    }

    $purchasing = reorderStaff('purchasing');
    expect($purchasing->can('create', ReorderSuggestion::class))->toBeFalse()
        ->and($purchasing->can('update', reorderFor($sku)))->toBeFalse()
        ->and($purchasing->can('delete', reorderFor($sku)))->toBeFalse()
        ->and(fn () => reorderFor($sku)->save())->toThrow(LogicException::class);
});
