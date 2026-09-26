<?php

use App\Filament\Resources\PurchaseOrderResource\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrderResource\Pages\ViewPurchaseOrder;
use App\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function purchasingStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

it('allows only admin and purchasing staff into supplier and PO pages', function () {
    foreach (['admin', 'purchasing'] as $role) {
        $this->actingAs(purchasingStaff($role))
            ->get('/admin/suppliers')->assertOk();
        $this->get('/admin/purchase-orders')->assertOk();
    }
});

it('refuses warehouse and accounts staff the purchasing pages and mutations', function () {
    foreach (['warehouse', 'accounts', 'rep', 'sales_manager'] as $role) {
        $user = purchasingStaff($role);
        $order = PurchaseOrder::factory()->draft()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($user)
            ->get('/admin/suppliers')->assertForbidden();
        $this->get('/admin/purchase-orders')->assertForbidden();
        expect($user->can('create', PurchaseOrder::class))->toBeFalse()
            ->and($user->can('update', $order))->toBeFalse()
            ->and($user->can('confirm', $order))->toBeFalse()
            ->and($user->can('cancel', $order))->toBeFalse()
            ->and($user->can('create', Supplier::class))->toBeFalse()
            ->and($user->can('update', $supplier))->toBeFalse();
    }
});

it('limits edits to drafts and never permits deletion', function () {
    $user = purchasingStaff('purchasing');
    $draft = PurchaseOrder::factory()->draft()->create();
    $confirmed = PurchaseOrder::factory()->create();

    expect($user->can('update', $draft))->toBeTrue()
        ->and($user->can('confirm', $draft))->toBeTrue()
        ->and($user->can('update', $confirmed))->toBeFalse()
        ->and($user->can('cancel', $confirmed))->toBeTrue()
        ->and($user->can('delete', $draft))->toBeFalse();

    $this->actingAs($user)
        ->get("/admin/purchase-orders/{$confirmed->id}/edit")
        ->assertForbidden();
});

it('creates and confirms a GBP purchase order through Filament', function () {
    $this->actingAs(purchasingStaff('purchasing'));
    $supplier = Supplier::factory()->create();
    $location = Location::factory()->default()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->outer(12)->create();

    $this->get('/admin/purchase-orders/create')->assertOk();
    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'expected_at' => now()->addDays(7)->toDateString(),
            'lines' => [[
                'sku_id' => $sku->id,
                'pack_id' => $pack->id,
                'pack_qty' => 5,
                'unit_fob' => '1.2345',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = PurchaseOrder::query()->sole();
    expect($order->status)->toBe('draft')
        ->and($order->lines()->sole()->base_qty)->toBe(60);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->id])
        ->callAction('confirm');

    expect($order->refresh()->status)->toBe('confirmed');
});

it('creates a GBP supplier through Filament', function () {
    $this->actingAs(purchasingStaff('purchasing'));

    $this->get('/admin/suppliers/create')->assertOk();
    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'code' => 'TEST-SUPPLIER',
            'name' => 'Test Supplier Ltd',
            'country_code' => 'GB',
            'default_currency' => 'GBP',
            'default_incoterm' => 'FOB',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Supplier::query()->where('code', 'TEST-SUPPLIER')->sole()->default_currency)->toBe('GBP');
});
