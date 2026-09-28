<?php

use App\Domain\Catalogue\CategoryClosureMaintainer;
use App\Domain\Catalogue\CategoryPath;
use App\Domain\Inventory\StockLabels;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockLevel;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 05.15 S3 — category, search and product pages.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->location = Location::factory()->default()->create();
    $this->baseList = PriceList::factory()->create(['scope' => 'base']);
    $this->taxClass = TaxClass::factory()->create();
    TaxRate::factory()->for($this->taxClass)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
});

function catalogueCategory(string $name, ?Category $parent = null): Category
{
    $factory = $parent === null ? Category::factory() : Category::factory()->childOf($parent->id);
    $category = $factory->create(['name' => $name, 'slug' => Str::slug($name)] + ($parent === null ? ['depth' => 0] : []));
    $category->update(['path' => CategoryPath::build($parent?->path, $category->id)]);
    (new CategoryClosureMaintainer)->recompute($category);

    return $category;
}

/** A priced, stocked product with one SKU and three packs, 10% off from 12 items. */
function catalogueProduct(string $name, ?Category $category = null, array $attributes = [], int $onHand = 100, ?string $skuCode = null): Product
{
    $product = Product::factory()->create(['name' => $name, 'slug' => Str::slug($name), 'primary_category_id' => $category?->id] + $attributes);
    $sku = Sku::factory()->create(['product_id' => $product->id, 'tax_class_id' => test()->taxClass->id] + ($skuCode ? ['sku_code' => $skuCode] : []));
    $each = Pack::factory()->for($sku)->create(['code' => 'EACH', 'label' => 'Each', 'base_units' => 1, 'is_default_sell' => true]);
    Pack::factory()->for($sku)->create(['code' => 'CASE12', 'label' => 'Case of 12', 'pack_level' => 'outer', 'base_units' => 12, 'is_default_sell' => false]);
    $sku->update(['default_pack_id' => $each->id]);
    PriceListItem::factory()->for(test()->baseList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => 10000]);
    PriceListItem::factory()->for(test()->baseList, 'priceList')->for($sku)->create(['min_base_qty' => 12, 'unit_price_e4' => 9000]);
    StockLevel::factory()->for($sku)->for(test()->location)->create(['on_hand_base_qty' => $onHand, 'allocated_base_qty' => 0]);
    SkuCost::factory()->for($sku)->create();

    return $product;
}

/** @return list<string> */
function listedNames(string $url): array
{
    return collect(test()->get($url)->assertOk()->viewData('page')['props']['products'])->pluck('name')->all();
}

it('lists a category with its whole subtree, and 404s an unknown or hidden one', function () {
    $kitchen = catalogueCategory('Kitchen');
    $cookware = catalogueCategory('Cookware', $kitchen);
    $bathroom = catalogueCategory('Bathroom');
    catalogueProduct('Frying Pan', $cookware);
    catalogueProduct('Kitchen Scale', $kitchen);
    catalogueProduct('Bath Mat', $bathroom);
    catalogueProduct('Draft Pan', $cookware, ['status' => 'draft']);

    expect(listedNames('/c/kitchen'))->toBe(['Frying Pan', 'Kitchen Scale'])
        ->and(listedNames('/c/cookware'))->toBe(['Frying Pan']);

    $this->get('/c/kitchen')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Listing', false)
        ->where('category.breadcrumb', [['name' => 'Kitchen', 'slug' => 'kitchen']])
        ->where('category.children', [['name' => 'Cookware', 'slug' => 'cookware']]));

    $this->get('/c/nothing-here')->assertNotFound();
    $bathroom->update(['status' => 'hidden']);
    $this->get('/c/bathroom')->assertNotFound();
});

it('searches names and SKU codes, and filters by brand and stock', function () {
    $acme = Brand::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    catalogueProduct('Frying Pan', attributes: ['brand_id' => $acme->id]);
    catalogueProduct('Saucepan', skuCode: 'ZX-4471-18');
    catalogueProduct('Pan Scourer', onHand: 0);
    catalogueProduct('Bath Mat');

    expect(listedNames('/search?q=pan'))->toBe(['Frying Pan', 'Pan Scourer', 'Saucepan'])
        // A SKU code that resembles no name, so only the code can match.
        ->and(listedNames('/search?q=ZX-4471'))->toBe(['Saucepan'])
        ->and(listedNames('/search?q=pan&brand=acme'))->toBe(['Frying Pan'])
        ->and(listedNames('/search?q=pan&in_stock=1'))->toBe(['Frying Pan', 'Saucepan']);

    $this->get('/search?q=pan')->assertInertia(fn (AssertableInertia $page) => $page->where('brands', [['slug' => 'acme', 'name' => 'Acme']]));
});

it('pages with a keyset cursor, never repeating a product, and sorts by newest', function () {
    foreach (range(1, 30) as $i) {
        catalogueProduct(sprintf('Item %02d', $i), attributes: ['published_at' => now()->subDays(40 - $i)]);
    }

    $first = $this->get('/search')->viewData('page')['props'];
    expect($first['products'])->toHaveCount(24)->and($first['next_cursor'])->toBeString();

    $second = $this->get('/search?after='.urlencode($first['next_cursor']))->viewData('page')['props'];
    $names = [...collect($first['products'])->pluck('name'), ...collect($second['products'])->pluck('name')];
    expect($second['products'])->toHaveCount(6)
        ->and($second['next_cursor'])->toBeNull()
        ->and($second['is_continuation'])->toBeTrue()
        ->and(array_unique($names))->toHaveCount(30);

    // A cursor minted under other filters restarts from the first page.
    expect(listedNames('/search?sort=newest&after='.urlencode($first['next_cursor']))[0])->toBe('Item 30');

    $newest = $this->get('/search?sort=newest')->viewData('page')['props'];
    $next = $this->get('/search?sort=newest&after='.urlencode($newest['next_cursor']))->viewData('page')['props'];
    expect(collect($newest['products'])->pluck('name')->first())->toBe('Item 30')
        ->and(collect($next['products'])->pluck('name')->all())->toBe(['Item 06', 'Item 05', 'Item 04', 'Item 03', 'Item 02', 'Item 01']);
});

it('shows a product with packs, the break table and a stock label, but no cost or stock figure', function () {
    $kitchen = catalogueCategory('Kitchen');
    catalogueProduct('Frying Pan', $kitchen, ['rrp_minor' => 1999, 'specifications' => ['Diameter' => '24 cm', 'Dishwasher safe' => true]]);
    catalogueProduct('Saucepan', $kitchen);

    $this->get('/p/frying-pan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Product', false)
        ->where('product.name', 'Frying Pan')
        ->where('product.breadcrumb', [['name' => 'Kitchen', 'slug' => 'kitchen']])
        ->where('product.specifications', [['label' => 'Diameter', 'value' => '24 cm'], ['label' => 'Dishwasher safe', 'value' => 'Yes']])
        ->where('product.variants.0.packs', [['code' => 'EACH', 'label' => 'Each', 'base_units' => 1], ['code' => 'CASE12', 'label' => 'Case of 12', 'base_units' => 12]])
        ->where('product.variants.0.default_pack_code', 'EACH')
        ->where('product.variants.0.price.breaks', [['min_base_qty' => 1, 'unit_net_e4' => 10000], ['min_base_qty' => 12, 'unit_net_e4' => 9000]])
        ->where('product.variants.0.stock', 'in_stock')
        // RRP is a trade selling aid (03 §11): not for a guest.
        ->where('product.rrp_minor', null)
        ->where('product.related.0.name', 'Saucepan')
        ->where('product.variants.0', fn ($v) => ! collect($v)->keys()->intersect(['unit_cost_e4', 'available_base_qty', 'on_hand_base_qty'])->count()));

    $company = Company::factory()->create();
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id]);
    $this->actingAs($buyer)->get('/p/frying-pan')->assertInertia(fn (AssertableInertia $page) => $page->where('product.rrp_minor', 1999));

    catalogueProduct('Draft Pan', attributes: ['status' => 'draft']);
    $this->get('/p/draft-pan')->assertNotFound();
});

it('gives the home page department tiles with product counts', function () {
    $kitchen = catalogueCategory('Kitchen');
    $cookware = catalogueCategory('Cookware', $kitchen);
    catalogueProduct('Frying Pan', $cookware);
    catalogueProduct('Kitchen Scale', $kitchen);
    catalogueCategory('Bathroom');

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('departments.0', ['slug' => 'kitchen', 'name' => 'Kitchen', 'product_count' => 2, 'image_url' => null])
        ->where('departments.1.product_count', 0));
});

it('puts the basket in the storefront shell for guests', function () {
    $this->get('/cart')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Cart/Index', false)
        ->where('shell.cart_count', 0));
});

it('gives guests and public customers stock labels on the order pad, and trade users the figures', function () {
    $sku = catalogueProduct('Frying Pan', onHand: 40)->skus()->sole();
    $url = '/api/v1/stock/availability?sku_ids='.$sku->public_id;

    $guest = $this->getJson($url)->assertOk()->json('data.0');
    expect($guest['available_base_qty'])->toBeNull()
        ->and($guest['incoming'])->toBeNull()
        ->and($guest['stock_label'])->toBe('in_stock');

    $public = User::factory()->create();
    expect($this->actingAs($public)->getJson($url)->json('data.0.available_base_qty'))->toBeNull();

    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => $buyer->id]);
    $trade = $this->actingAs($buyer)->getJson($url)->json('data.0');
    expect($trade['available_base_qty'])->toBe(40)
        ->and($trade['stock_label'])->toBe('in_stock');
});

it('shows the public a stock figure only at 10 or fewer, as marketplaces do', function () {
    $few = catalogueProduct('Few Pans', onHand: 7)->skus()->sole();
    $many = catalogueProduct('Many Pans', onHand: 40)->skus()->sole();
    $reorder = catalogueProduct('Reorder Pans', onHand: 40)->skus()->sole();
    StockLevel::query()->where('sku_id', $reorder->id)->update(['reorder_point_base_qty' => 50]);

    expect((new StockLabels)->detailed([$few->id, $many->id, $reorder->id]))->toBe([
        $few->id => ['label' => 'low_stock', 'left' => 7],
        $many->id => ['label' => 'in_stock', 'left' => null],
        $reorder->id => ['label' => 'low_stock', 'left' => null],
    ]);

    $guest = collect($this->getJson("/api/v1/stock/availability?sku_ids={$few->public_id},{$many->public_id}")->json('data'))->keyBy('sku_id');
    expect($guest[$few->public_id]['stock_left'])->toBe(7)
        ->and($guest[$many->public_id]['stock_left'])->toBeNull()
        ->and($guest[$many->public_id]['available_base_qty'])->toBeNull();

    $this->get('/p/few-pans')->assertInertia(fn (AssertableInertia $page) => $page->where('product.variants.0.stock_left', 7));
});

it('reports a shortage to the public without a figure above 10, and with one at 10 or fewer', function () {
    $many = catalogueProduct('Many Pans', onHand: 40)->skus()->sole();
    $few = catalogueProduct('Few Pans', onHand: 7)->skus()->sole();
    $public = User::factory()->create();

    $this->actingAs($public)->postJson('/api/v1/cart/lines', ['sku_id' => $many->public_id, 'pack_qty' => 500])->assertSuccessful();
    $this->actingAs($public)->postJson('/api/v1/cart/lines', ['sku_id' => $few->public_id, 'pack_qty' => 9])->assertSuccessful();
    $blockers = collect($this->actingAs($public)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])->assertOk()->json('blockers'))
        ->where('code', 'insufficient_stock')->keyBy(fn ($b) => $b['meta']['sku_id']);

    expect($blockers[$many->public_id]['meta']['available_base_qty'])->toBeNull()
        ->and($blockers[$many->public_id]['message'])->not->toContain('40')
        ->and($blockers[$few->public_id]['meta']['available_base_qty'])->toBe(7)
        ->and($blockers[$few->public_id]['message'])->toBe('Only 7 left.');

    // A trade buyer is told the figure.
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => $buyer->id]);
    $this->actingAs($buyer)->postJson('/api/v1/cart/lines', ['sku_id' => $many->public_id, 'pack_qty' => 500])->assertSuccessful();
    $trade = collect($this->actingAs($buyer)->postJson('/api/v1/checkout/preview', ['delivery_country_code' => 'GB'])->assertOk()->json('blockers'))->firstWhere('code', 'insufficient_stock');
    expect($trade['meta']['available_base_qty'])->toBe(40);
});

it('offers quick add only when one tap is enough, and suggests products as the buyer types', function () {
    catalogueProduct('Frying Pan');
    $moq = catalogueProduct('Bulk Pan')->skus()->sole();
    $moq->update(['moq_base_qty' => 6]);
    catalogueProduct('Sold Out Pan', onHand: 0);

    $cards = collect($this->get('/search?q=pan')->viewData('page')['props']['products'])->keyBy('name');
    expect($cards['Frying Pan']['quick_add']['pack_code'])->toBe('EACH')
        ->and($cards['Bulk Pan']['quick_add'])->toBeNull()
        ->and($cards['Sold Out Pan']['quick_add'])->toBeNull();

    $suggest = $this->getJson('/search/suggest?q=pan')->assertOk()->json('data');
    expect(collect($suggest)->pluck('name')->all())->toBe(['Bulk Pan', 'Frying Pan', 'Sold Out Pan'])
        ->and(array_keys($suggest[0]))->toBe(['name', 'slug', 'thumbnail_url', 'price'])
        ->and($this->getJson('/search/suggest?q=p')->json('data'))->toBe([]);
});
