<?php

use App\Domain\Catalogue\CategoryClosureMaintainer;
use App\Domain\Catalogue\CategoryPath;
use App\Domain\Catalogue\OrderPadCatalogue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Sku;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 05.1 §3 U2 / §4.1: search on SKU code and product name, category
 * (with its subtree), brand and "in stock only" — server-side, in the
 * one catalogue query, with keyset paging that restarts when the filters
 * change.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->location = Location::factory()->default()->create();

    // path and closure rows are maintained by the catalogue service, not
    // the factory — set here the way DemoDataSeeder does.
    $category = function (string $name, ?Category $parent = null): Category {
        $category = $parent === null
            ? Category::factory()->create(['name' => $name, 'slug' => strtolower($name)])
            : Category::factory()->childOf($parent->id)->create(['name' => $name, 'slug' => strtolower($name)]);
        $category->update(['path' => CategoryPath::build($parent?->path, $category->id)]);
        (new CategoryClosureMaintainer)->recompute($category);

        return $category;
    };
    $this->kitchen = $category('Kitchen');
    $this->utensils = $category('Utensils', $this->kitchen);
    $this->garden = $category('Garden');

    $this->acme = Brand::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Brand::factory()->create(['name' => 'Other', 'slug' => 'other']);
});

/**
 * @param  array<string, mixed>  $productAttributes
 * @param  array<string, mixed>  $skuAttributes
 */
function padSku(string $name, string $code, Category $category, Brand $brand, ?int $available = 100, array $skuAttributes = []): Sku
{
    $product = Product::factory()->create(['name' => $name, 'primary_category_id' => $category->id, 'brand_id' => $brand->id]);
    $sku = Sku::factory()->for($product)->create(['sku_code' => $code] + $skuAttributes);
    Pack::factory()->for($sku)->create();
    if ($available !== null) {
        StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $available, 'allocated_base_qty' => 0]);
    }

    return $sku;
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function padProps(array $query = []): array
{
    return test()->get('/order-pad?'.http_build_query($query))->assertOk()->viewData('page')['props'];
}

/**
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function padCodes(array $query = []): array
{
    return array_column(padProps($query)['catalogue']['rows'], 'sku_code');
}

it('searches SKU code and product name, case-insensitively, by substring', function () {
    padSku('Stainless Grater 4-side', 'LTC1179', $this->utensils, $this->acme);
    padSku('Garden Trowel', 'GRD0042', $this->garden, $this->other);
    padSku('Pastry Brush', 'LTC2001', $this->kitchen, $this->acme);

    expect(padCodes(['q' => 'grater']))->toBe(['LTC1179'])
        ->and(padCodes(['q' => 'ltc']))->toBe(['LTC2001', 'LTC1179'])
        ->and(padCodes(['q' => 'grd004']))->toBe(['GRD0042'])
        ->and(padCodes(['q' => '100%_']))->toBe([])
        ->and(padCodes(['q' => '   ']))->toHaveCount(3);
});

it('tolerates a typo in the product name (pg_trgm)', function () {
    padSku('Stainless Grater', 'LTC1179', $this->utensils, $this->acme);
    padSku('Garden Trowel', 'GRD0042', $this->garden, $this->other);

    expect(padCodes(['q' => 'stainles grater']))->toBe(['LTC1179']);
});

it('filters by category including its subcategories, and by brand', function () {
    padSku('Grater', 'A1', $this->utensils, $this->acme);
    padSku('Pastry Brush', 'A2', $this->kitchen, $this->other);
    padSku('Trowel', 'A3', $this->garden, $this->acme);

    expect(padCodes(['category' => 'kitchen']))->toBe(['A1', 'A2'])
        ->and(padCodes(['category' => 'utensils']))->toBe(['A1'])
        ->and(padCodes(['brand' => 'acme']))->toBe(['A1', 'A3'])
        ->and(padCodes(['category' => 'kitchen', 'brand' => 'acme']))->toBe(['A1'])
        ->and(padCodes(['category' => 'no-such-category']))->toBe([]);
});

it('keeps only SKUs with available stock, or not stock-tracked, when in_stock is set', function () {
    padSku('Alpha', 'IN', $this->kitchen, $this->acme, available: 5);
    padSku('Bravo', 'OUT', $this->kitchen, $this->acme, available: 0);
    padSku('Charlie', 'NONE', $this->kitchen, $this->acme, available: null);
    padSku('Delta', 'BACKORDER', $this->kitchen, $this->acme, available: 0, skuAttributes: ['allow_backorder' => true]);
    padSku('Echo', 'UNTRACKED', $this->kitchen, $this->acme, available: null, skuAttributes: ['is_stock_tracked' => false]);

    expect(padCodes(['in_stock' => '1']))->toBe(['IN', 'UNTRACKED'])
        ->and(padCodes(['in_stock' => '0']))->toHaveCount(5);
});

it('echoes normalised filters and lists facet options', function () {
    $props = padProps(['q' => '  grater  ', 'category' => 'kitchen', 'in_stock' => 'true']);

    expect($props['filters'])->toBe(['q' => 'grater', 'category' => 'kitchen', 'brand' => null, 'in_stock' => true])
        ->and($props['facets']['categories'])->toBe([
            ['slug' => 'kitchen', 'name' => 'Kitchen', 'depth' => 0],
            ['slug' => 'utensils', 'name' => 'Utensils', 'depth' => 1],
            ['slug' => 'garden', 'name' => 'Garden', 'depth' => 0],
        ])
        ->and(array_column($props['facets']['brands'], 'slug'))->toBe(['acme', 'other']);
});

it('pages a filtered result by keyset, and restarts when the filters change', function () {
    for ($i = 0; $i < OrderPadCatalogue::PAGE_SIZE + 5; $i++) {
        padSku(sprintf('Grater %03d', $i), sprintf('G%03d', $i), $this->kitchen, $this->acme);
    }
    padSku('Trowel', 'T001', $this->garden, $this->acme);

    $first = padProps(['q' => 'grater'])['catalogue'];
    expect($first['rows'])->toHaveCount(OrderPadCatalogue::PAGE_SIZE)
        ->and($first['next_cursor'])->not->toBeNull();

    $second = padProps(['q' => 'grater', 'after' => $first['next_cursor']])['catalogue'];
    expect(array_column($second['rows'], 'sku_code'))->toBe(['G050', 'G051', 'G052', 'G053', 'G054'])
        ->and($second['start_row'])->toBe(OrderPadCatalogue::PAGE_SIZE + 1)
        ->and($second['next_cursor'])->toBeNull();

    // The same cursor under different filters is ignored: back to row 1.
    $restarted = padProps(['q' => 'trowel', 'after' => $first['next_cursor']])['catalogue'];
    expect(array_column($restarted['rows'], 'sku_code'))->toBe(['T001'])
        ->and($restarted['start_row'])->toBe(1);
});
