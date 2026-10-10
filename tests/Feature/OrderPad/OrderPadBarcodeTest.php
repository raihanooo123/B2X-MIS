<?php

use App\Models\Location;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * 05.1 §8.2 / §11: a scanned barcode is matched against
 * `skus.barcode_ean` then `packs.barcode`, exactly; the pad gets the row
 * and, for a case barcode, the pack to preselect. The camera and the
 * focus happen in the browser; this covers what the server answers.
 */
beforeEach(function () {
    $this->withoutVite();
    Location::factory()->default()->create();
});

/** An active SKU with a default EACH pack and an outer of 12. */
function scanPadSku(string $code, ?string $ean = null, ?string $caseBarcode = null, bool $caseSellable = true): Sku
{
    $sku = Sku::factory()->for(Product::factory())->create(['sku_code' => $code, 'barcode_ean' => $ean]);
    Pack::factory()->for($sku)->create();
    Pack::factory()->for($sku)->outer(12)->create(['barcode' => $caseBarcode, 'is_sellable' => $caseSellable]);

    return $sku;
}

/** @return array<string, mixed> */
function scanPadProps(?string $q): array
{
    return test()->get('/order-pad?'.http_build_query(array_filter(['q' => $q])))->assertOk()->viewData('page')['props'];
}

it('finds the row by its SKU barcode and keeps the default pack', function () {
    $sku = scanPadSku('LTC-1', ean: '5012345678900');
    scanPadSku('LTC-2', ean: '5012345678917');

    $props = scanPadProps('5012345678900');

    expect(array_column($props['catalogue']['rows'], 'sku_id'))->toBe([$sku->public_id])
        ->and($props['scan'])->toBe(['code' => '5012345678900', 'sku_id' => $sku->public_id, 'pack_code' => null]);
});

it('finds the row by a case barcode and names that pack', function () {
    $sku = scanPadSku('LTC-1', caseBarcode: '15012345678907');

    $props = scanPadProps('15012345678907');

    expect(array_column($props['catalogue']['rows'], 'sku_id'))->toBe([$sku->public_id])
        ->and($props['scan'])->toBe(['code' => '15012345678907', 'sku_id' => $sku->public_id, 'pack_code' => 'OUTER12']);
});

it('prefers the SKU barcode when a case barcode on another SKU is the same code', function () {
    $bySku = scanPadSku('LTC-1', ean: '0000000000017');
    scanPadSku('LTC-2', caseBarcode: '0000000000017');

    expect(scanPadProps('0000000000017')['scan']['sku_id'])->toBe($bySku->public_id);
});

it('does not preselect a case that cannot be sold on its own', function () {
    $sku = scanPadSku('LTC-1', caseBarcode: '15012345678907', caseSellable: false);

    expect(scanPadProps('15012345678907')['scan'])->toBe(['code' => '15012345678907', 'sku_id' => $sku->public_id, 'pack_code' => null]);
});

it('matches barcodes exactly, never a partial code', function () {
    scanPadSku('LTC-1', ean: '5012345678900');

    $props = scanPadProps('501234567');

    expect($props['catalogue']['rows'])->toBe([])
        ->and($props['scan'])->toBeNull();
});

it('reports the match but shows no row for a SKU that is not on sale', function () {
    $sku = scanPadSku('LTC-1', ean: '5012345678900');
    DB::table('skus')->where('id', $sku->id)->update(['status' => 'discontinued']);

    $props = scanPadProps('5012345678900');

    expect($props['catalogue']['rows'])->toBe([])
        ->and($props['scan']['sku_id'])->toBe($sku->public_id);
});

it('ignores a deleted SKU and answers no scan without a search', function () {
    $sku = scanPadSku('LTC-1', ean: '5012345678900');
    DB::table('skus')->where('id', $sku->id)->update(['deleted_at' => now()]);

    expect(scanPadProps('5012345678900')['scan'])->toBeNull()
        ->and(scanPadProps(null)['scan'])->toBeNull();
});
