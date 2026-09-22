<?php

use App\Filament\Resources\BrandResource;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\SkuResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

function userWithRole(?string $roleCode): User
{
    $user = User::factory()->create();

    if ($roleCode !== null) {
        $role = Role::factory()->create(['code' => $roleCode]);
        RoleUser::create(['role_id' => $role->id, 'user_id' => $user->id]);
    }

    return $user;
}

/**
 * The full role x resource visibility matrix from the brief, asserted
 * directly against Resource::canViewAny() — the exact call Filament
 * itself makes both to decide whether a nav item registers
 * (Resource::registerNavigationItems()) and to gate the index page, so
 * this is not a re-implementation of Filament's own check, it's the
 * real one.
 */
it('lets each role view exactly the catalogue resources it should', function (?string $roleCode, array $expectedVisible) {
    Auth::login(userWithRole($roleCode));

    expect(BrandResource::canViewAny())->toBe(in_array('brand', $expectedVisible, true))
        ->and(CategoryResource::canViewAny())->toBe(in_array('category', $expectedVisible, true))
        ->and(ProductResource::canViewAny())->toBe(in_array('product', $expectedVisible, true))
        ->and(SkuResource::canViewAny())->toBe(in_array('sku', $expectedVisible, true))
        ->and(Auth::user()->can('viewAny', Pack::class))->toBe(in_array('pack', $expectedVisible, true));
})->with([
    'admin sees everything' => ['admin', ['brand', 'category', 'product', 'sku', 'pack']],
    'purchasing sees everything' => ['purchasing', ['brand', 'category', 'product', 'sku', 'pack']],
    'rep sees everything' => ['rep', ['brand', 'category', 'product', 'sku', 'pack']],
    'sales_manager sees everything' => ['sales_manager', ['brand', 'category', 'product', 'sku', 'pack']],
    'warehouse sees only Product/Sku/Pack' => ['warehouse', ['product', 'sku', 'pack']],
    'accounts sees only Product/Sku/Pack' => ['accounts', ['product', 'sku', 'pack']],
    'no role sees nothing (deny-by-default)' => [null, []],
]);

/**
 * Create/update: admin only, everywhere — matching Resource::canCreate()
 * and Resource::canEdit(), the exact checks Filament uses to show or
 * hide the Create button and Edit table action.
 */
it('lets only admin create or edit catalogue records', function (?string $roleCode, bool $canManage) {
    Auth::login(userWithRole($roleCode));

    $brand = Brand::factory()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create();
    $sku = Sku::factory()->create();

    expect(BrandResource::canCreate())->toBe($canManage)
        ->and(BrandResource::canEdit($brand))->toBe($canManage)
        ->and(CategoryResource::canCreate())->toBe($canManage)
        ->and(CategoryResource::canEdit($category))->toBe($canManage)
        ->and(ProductResource::canCreate())->toBe($canManage)
        ->and(ProductResource::canEdit($product))->toBe($canManage)
        ->and(SkuResource::canCreate())->toBe($canManage)
        ->and(SkuResource::canEdit($sku))->toBe($canManage);
})->with([
    'admin' => ['admin', true],
    'purchasing' => ['purchasing', false],
    'rep' => ['rep', false],
    'sales_manager' => ['sales_manager', false],
    'warehouse' => ['warehouse', false],
    'accounts' => ['accounts', false],
]);

/**
 * "Nobody gets delete" — including admin. Asserted directly against
 * every Policy's delete/deleteAny (and the soft-delete family on Sku,
 * the only one of the five that uses SoftDeletes) rather than through
 * the UI, since there is no delete action left in the UI to click.
 */
it('denies delete to every role, including admin', function (?string $roleCode) {
    $user = userWithRole($roleCode);

    $brand = Brand::factory()->create();
    $category = Category::factory()->create();
    $product = Product::factory()->create();
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create();

    expect($user->can('delete', $brand))->toBeFalse()
        ->and($user->can('deleteAny', Brand::class))->toBeFalse()
        ->and($user->can('delete', $category))->toBeFalse()
        ->and($user->can('deleteAny', Category::class))->toBeFalse()
        ->and($user->can('delete', $product))->toBeFalse()
        ->and($user->can('deleteAny', Product::class))->toBeFalse()
        ->and($user->can('delete', $sku))->toBeFalse()
        ->and($user->can('deleteAny', Sku::class))->toBeFalse()
        ->and($user->can('restore', $sku))->toBeFalse()
        ->and($user->can('forceDelete', $sku))->toBeFalse()
        ->and($user->can('delete', $pack))->toBeFalse()
        ->and($user->can('deleteAny', Pack::class))->toBeFalse();
})->with(['admin', 'purchasing', 'rep', 'sales_manager', 'warehouse', 'accounts', 'no role' => null]);

/**
 * End-to-end proof, not just the Policy in isolation: the actual admin
 * panel HTTP routes return 200 for a resource the role can see and 403
 * for one it can't — this is what CreateProduct/EditSku/etc.'s own
 * `mount()` authorization actually produces for a real request.
 */
it('returns 403 on the actual HTTP route for a resource warehouse cannot see, 200 for one it can', function () {
    $warehouse = userWithRole('warehouse');

    $this->actingAs($warehouse)
        ->get(ProductResource::getUrl('index'))
        ->assertSuccessful();

    $this->actingAs($warehouse)
        ->get(BrandResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($warehouse)
        ->get(CategoryResource::getUrl('index'))
        ->assertForbidden();
});

it('returns 403 on the create route for a view-only role', function () {
    $rep = userWithRole('rep');

    $this->actingAs($rep)
        ->get(ProductResource::getUrl('index'))
        ->assertSuccessful();

    $this->actingAs($rep)
        ->get(ProductResource::getUrl('create'))
        ->assertForbidden();
});
