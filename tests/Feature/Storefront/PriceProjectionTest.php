<?php

use App\Domain\Storefront\ProductCards;
use App\Domain\Storefront\StorefrontCatalogue;
use App\Domain\Storefront\StorefrontFilters;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Sku;
use App\Models\TaxClass;
use App\Models\TaxRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 02 §29 — the storefront price sort key: exactly the card's "from" price,
 * kept fresh by writes, time boundaries and a nightly rebuild, paged in two
 * phases with unpriced products last, for guests and public customers only.
 */
beforeEach(function () {
    $this->withoutVite();
    Location::factory()->default()->create();
    $this->baseList = PriceList::factory()->create(['scope' => 'base']);
    $this->standard = TaxClass::factory()->create();
    TaxRate::factory()->for($this->standard)->create(['country_code' => 'GB', 'rate_bp' => 2000]);
    $this->zero = TaxClass::factory()->create();
    TaxRate::factory()->for($this->zero)->create(['country_code' => 'GB', 'rate_bp' => 0]);
});

/**
 * An active product; each SKU is [unit net e4, tax class] or null for no base price.
 *
 * @param  list<array{0: int|null, 1: TaxClass}>  $skus
 */
function projProduct(string $name, array $skus): Product
{
    $product = Product::factory()->create(['name' => $name, 'slug' => Str::slug($name), 'primary_category_id' => null]);
    foreach ($skus as $i => [$netE4, $taxClass]) {
        $sku = Sku::factory()->create(['product_id' => $product->id, 'tax_class_id' => $taxClass->id, 'position' => $i]);
        Pack::factory()->for($sku)->create(['base_units' => 1]);
        if ($netE4 !== null) {
            PriceListItem::factory()->for(test()->baseList, 'priceList')->for($sku)->create(['min_base_qty' => 1, 'unit_price_e4' => $netE4]);
        }
    }

    return $product;
}

/** @return array<string, mixed> */
function projRow(Product $product): array
{
    return (array) DB::table('product_price_projections')->where('product_id', $product->id)->first();
}

/** Every product id in price order, following cursors page by page. */
function projWalk(string $sort, string $mode = 'gross', int $pageSize = 2): array
{
    $ids = [];
    $cursor = null;
    do {
        $page = (new StorefrontCatalogue)->page(new StorefrontFilters(sort: $sort), $cursor, $pageSize, $mode);
        $ids = [...$ids, ...$page['product_ids']];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    return $ids;
}

it('holds exactly the from price the card shows a guest, the gross of the net-cheapest SKU', function () {
    // SKU A £1.00 net at 0% (gross £1.00); SKU B £0.90 net at 20% (gross £1.08). The card picks the lowest net: B.
    $product = projProduct('Mixed VAT', [[10000, $this->zero], [9000, $this->standard]]);

    $card = (new ProductCards)->cards([$product->id], null)[0]['price'];
    $row = projRow($product);

    expect($card['unit_net_e4'])->toBe(9000)
        ->and((int) $row['from_unit_net_e4'])->toBe($card['unit_net_e4'])
        ->and((int) $row['tax_rate_bp'])->toBe($card['tax_rate_bp'])
        ->and((int) $row['from_unit_gross_e4'])->toBe(10800)
        ->and((int) $row['price_list_id'])->toBe($this->baseList->id);
});

it('follows price, product and SKU writes, and keeps unpriced active products as rows of nulls', function () {
    $priced = projProduct('Priced Pan', [[10000, $this->standard]]);
    $unpriced = projProduct('Unpriced Pan', [[null, $this->standard]]);

    expect(projRow($unpriced)['from_unit_gross_e4'])->toBeNull()
        ->and(projRow($unpriced)['product_id'])->toBe($unpriced->id);

    $item = PriceListItem::query()->where('sku_id', $priced->skus()->sole()->id)->sole();
    $item->update(['unit_price_e4' => 25000]);
    expect((int) projRow($priced)['from_unit_gross_e4'])->toBe(30000);

    $priced->update(['status' => 'discontinued']);
    expect(projRow($priced))->toBe([]);
});

it('refreshes a row when a scheduled base list takes effect', function () {
    // Relative to the real clock, never behind it: the base list and tax rate
    // made in beforeEach start at the database's now(), which travelTo()
    // cannot move. Fixed dates broke this test once they fell into the past.
    $now = CarbonImmutable::now('UTC')->addSecond();
    $switch = $now->addDay()->startOfDay();
    $this->travelTo($now);
    DB::table('price_lists')->where('id', $this->baseList->id)->update(['validity' => DB::raw("tstzrange('2026-01-01', '{$switch->toIso8601String()}', '[)')")]);
    $next = PriceList::factory()->create(['scope' => 'base', 'validity' => '['.$switch->toIso8601String().',)']);
    $product = projProduct('Scheduled Pan', [[10000, $this->standard]]);
    PriceListItem::factory()->for($next, 'priceList')->for($product->skus()->sole())->create(['min_base_qty' => 1, 'unit_price_e4' => 50000]);

    expect((int) projRow($product)['from_unit_net_e4'])->toBe(10000)
        ->and(CarbonImmutable::parse(projRow($product)['stale_after'])->equalTo($switch))->toBeTrue();

    $this->travelTo($now->addDays(2));
    $this->artisan('storefront:refresh-price-projection --stale')->assertSuccessful();

    expect((int) projRow($product)['from_unit_net_e4'])->toBe(50000);
});

it('corrects drift in the nightly rebuild, and removes rows for products that went', function () {
    $product = projProduct('Drifting Pan', [[10000, $this->standard]]);
    DB::table('product_price_projections')->where('product_id', $product->id)->update(['from_unit_gross_e4' => 1, 'from_unit_net_e4' => 1]);
    DB::table('products')->where('id', $product->id)->update(['status' => 'archived']);
    $kept = projProduct('Kept Pan', [[20000, $this->standard]]);
    DB::table('product_price_projections')->where('product_id', $kept->id)->update(['from_unit_gross_e4' => 7, 'from_unit_net_e4' => 7]);

    $this->artisan('storefront:refresh-price-projection')->expectsOutputToContain('2 row(s) had drifted')->assertSuccessful();

    expect(projRow($product))->toBe([])
        ->and((int) projRow($kept)['from_unit_gross_e4'])->toBe(24000);
});

it('pages by price in two phases, both ways, with unpriced products last', function () {
    $c = projProduct('C', [[30000, $this->standard]]);
    $a = projProduct('A', [[10000, $this->standard]]);
    $none1 = projProduct('Unpriced 1', [[null, $this->standard]]);
    $d = projProduct('D', [[40000, $this->standard]]);
    $b = projProduct('B', [[20000, $this->standard]]);
    $none2 = projProduct('Unpriced 2', [[null, $this->standard]]);

    expect(projWalk('price_asc'))->toBe([$a->id, $b->id, $c->id, $d->id, $none1->id, $none2->id])
        ->and(projWalk('price_desc'))->toBe([$d->id, $c->id, $b->id, $a->id, $none1->id, $none2->id])
        ->and(projWalk('price_asc', pageSize: 4))->toBe([$a->id, $b->id, $c->id, $d->id, $none1->id, $none2->id]);
});

it('orders by the price the viewer sees: inc VAT or ex VAT', function () {
    $zeroRated = projProduct('Zero rated', [[10000, $this->zero]]);   // £1.00 net, £1.00 gross
    $standard = projProduct('Standard', [[9000, $this->standard]]);   // £0.90 net, £1.08 gross

    expect(projWalk('price_asc', 'gross'))->toBe([$zeroRated->id, $standard->id])
        ->and(projWalk('price_asc', 'net'))->toBe([$standard->id, $zeroRated->id]);
});

it('offers price sorts to guests and public customers, never to trade', function () {
    Category::factory()->create(['slug' => 'pans', 'status' => 'active']);

    $this->get('/c/pans?sort=price_asc')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Listing', false)
        ->where('filters.sort', 'price_asc')
        ->where('price_sorts', true));

    $company = Company::factory()->create();
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id]);

    $this->actingAs($buyer)->get('/c/pans?sort=price_asc')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Storefront/Listing', false)
        ->where('filters.sort', 'name')
        ->where('price_sorts', false));
});

it('is read only by the catalogue and written only by the projector (invariant 3)', function () {
    $allowed = [
        'app/Domain/Storefront/ProductPriceProjector.php',
        'app/Domain/Storefront/StorefrontCatalogue.php',
    ];
    $users = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
            && str_contains((string) file_get_contents($file->getPathname()), 'product_price_projections')) {
            $users[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }
    sort($users);

    expect($users)->toBe($allowed);
});

it('serves the first price page from the sort index, with no sort node, at 5,000 products', function () {
    $taxClass = $this->standard->id;
    DB::statement(<<<'SQL'
        INSERT INTO products (public_id, name, slug, status)
        SELECT 'explain-'||g, 'Explain '||g, 'explain-'||g, 'active' FROM generate_series(1, 5000) g
    SQL);
    DB::statement(<<<SQL
        INSERT INTO skus (product_id, tax_class_id, public_id, sku_code, status)
        SELECT p.id, {$taxClass}, 'explain-sku-'||p.id, 'EXP-'||p.id, 'active' FROM products p WHERE p.slug LIKE 'explain-%'
    SQL);
    DB::statement(<<<SQL
        INSERT INTO product_price_projections (product_id, from_sku_id, price_list_id, from_unit_net_e4, tax_rate_bp, from_unit_gross_e4)
        SELECT p.id, s.id, {$this->baseList->id}, (p.id * 37) % 100000, 2000, ((p.id * 37) % 100000) * 12 / 10
        FROM products p JOIN skus s ON s.product_id = p.id WHERE p.slug LIKE 'explain-%'
    SQL);
    DB::statement('ANALYZE products');
    DB::statement('ANALYZE skus');
    DB::statement('ANALYZE product_price_projections');

    $sql = <<<'SQL'
        SELECT p.id, pp.from_unit_gross_e4 FROM products p
        JOIN product_price_projections pp ON pp.product_id = p.id
        WHERE p.status = 'active' AND p.deleted_at IS NULL AND pp.from_unit_gross_e4 IS NOT NULL
          AND EXISTS (SELECT 1 FROM skus s WHERE s.product_id = p.id AND s.status = 'active' AND s.deleted_at IS NULL)
        ORDER BY pp.from_unit_gross_e4, p.id LIMIT 25
    SQL;
    $rows = DB::select('EXPLAIN (FORMAT JSON) '.$sql);
    $plan = json_decode($rows[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0]['Plan'];

    $nodes = [];
    $walk = function (array $node) use (&$walk, &$nodes): void {
        $nodes[] = $node;
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($plan);

    expect(collect($nodes)->pluck('Index Name')->filter()->all())->toContain('product_price_projections_gross_idx')
        ->and(collect($nodes)->whereIn('Node Type', ['Sort', 'Incremental Sort'])->all())->toBe([]);
});
