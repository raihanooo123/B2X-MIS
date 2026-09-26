<?php

use App\Filament\Resources\OutstandingPurchaseOrderResource;
use App\Filament\Resources\OutstandingPurchaseOrderResource\Pages\ListOutstandingPurchaseOrders;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function outstandingStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

it('shows only remaining lines on open POs, with the line date taking precedence', function () {
    $supplier = Supplier::factory()->create();
    $location = Location::factory()->default()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->outer(12)->create();
    $expected = now()->addDays(5)->toDateString();
    $lineExpected = now()->addDays(7)->toDateString();
    $openPo = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'location_id' => $location->id, 'expected_at' => $expected]);
    $due = PurchaseOrderLine::factory()->for($openPo)->forPack($pack, 5)->create(['received_base_qty' => 12, 'expected_at' => $lineExpected]);
    $otherOpenPo = PurchaseOrder::factory()->create(['status' => 'part_received']);
    $otherDue = PurchaseOrderLine::factory()->for($otherOpenPo)->create(['received_base_qty' => 2]);
    $fullyReceived = PurchaseOrderLine::factory()->for(PurchaseOrder::factory()->create())->create(['received_base_qty' => 10]);
    $draft = PurchaseOrderLine::factory()->for(PurchaseOrder::factory()->draft()->create())->create();
    $cancelled = PurchaseOrderLine::factory()->for(PurchaseOrder::factory()->create(['status' => 'cancelled']))->create();
    $received = PurchaseOrderLine::factory()->for(PurchaseOrder::factory()->create(['status' => 'received']))->create();

    expect(OutstandingPurchaseOrderResource::getEloquentQuery()->pluck('id')->sort()->values()->all())
        ->toBe([$due->id, $otherDue->id]);

    $this->actingAs(outstandingStaff('purchasing'))
        ->get('/admin/outstanding-purchase-orders')
        ->assertOk()
        ->assertSee('Outstanding POs')
        ->assertDontSee('Purchase Order Lines');
    Livewire::test(ListOutstandingPurchaseOrders::class)
        ->assertCanSeeTableRecords([$due, $otherDue])
        ->assertCanNotSeeTableRecords([$fullyReceived, $draft, $cancelled, $received])
        ->assertTableColumnStateSet('units_due', 48, $due)
        ->assertTableColumnStateSet('expected_date', $due->expected_at, $due)
        ->assertTableColumnStateSet('expected_date', $otherOpenPo->expected_at, $otherDue)
        ->filterTable('location', $location->id)
        ->assertCanSeeTableRecords([$due])
        ->assertCanNotSeeTableRecords([$otherDue]);

    Livewire::test(ListOutstandingPurchaseOrders::class)
        ->searchTable($openPo->po_number)
        ->assertCanSeeTableRecords([$due])
        ->assertCanNotSeeTableRecords([$otherDue])
        ->searchTable($supplier->name)
        ->assertCanSeeTableRecords([$due])
        ->searchTable($due->sku_code_snapshot)
        ->assertCanSeeTableRecords([$due]);
});

it('keeps the read-only outstanding view restricted to admin and purchasing', function () {
    foreach (['admin', 'purchasing'] as $role) {
        $this->actingAs(outstandingStaff($role))
            ->get('/admin/outstanding-purchase-orders')->assertOk();
    }

    foreach (['warehouse', 'accounts', 'rep', 'sales_manager'] as $role) {
        $user = outstandingStaff($role);
        $this->actingAs($user)
            ->get('/admin/outstanding-purchase-orders')->assertForbidden();
        expect($user->can('viewAny', PurchaseOrderLine::class))->toBeFalse();
    }

    $purchasing = outstandingStaff('purchasing');
    $line = PurchaseOrderLine::factory()->create();
    expect($purchasing->can('create', PurchaseOrderLine::class))->toBeFalse()
        ->and($purchasing->can('update', $line))->toBeFalse()
        ->and($purchasing->can('delete', $line))->toBeFalse();
});
