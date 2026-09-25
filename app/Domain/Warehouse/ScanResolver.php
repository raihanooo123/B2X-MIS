<?php

namespace App\Domain\Warehouse;

use App\Models\Bin;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\Sku;
use Illuminate\Support\Facades\Log;

/**
 * "Every screen that asks for an identifier accepts a scan" (05.5 §9).
 * One scanned or typed code, resolved to what it identifies, most
 * specific first:
 *
 *   purchase order number → container reference → case barcode
 *   (`packs.barcode`, which also fixes the pack) → SKU barcode
 *   (`skus.barcode_ean`) → SKU code → bin code at the receipt's location.
 *
 * A code that matches nothing is not a failure (05.5 §12): it is logged
 * for catalogue correction and answered with search candidates.
 */
final class ScanResolver
{
    private const SEARCH_LIMIT = 10;

    /**
     * @return array{kind: 'purchase_order', purchase_order: PurchaseOrder}
     *                                                                      |array{kind: 'container', container: Container}
     *                                                                      |array{kind: 'sku', sku: Sku, pack: ?Pack}
     *                                                                      |array{kind: 'bin', bin: Bin}
     *                                                                      |array{kind: 'unknown', candidates: list<Sku>}
     */
    public function resolve(string $code, ?GoodsReceipt $receipt): array
    {
        $code = trim($code);

        $po = PurchaseOrder::query()->where('po_number', $code)->first();
        if ($po !== null) {
            return ['kind' => 'purchase_order', 'purchase_order' => $po];
        }

        $container = Container::query()->where('container_ref', $code)->first();
        if ($container !== null) {
            return ['kind' => 'container', 'container' => $container];
        }

        $pack = Pack::query()->where('barcode', $code)->first();
        if ($pack !== null) {
            return ['kind' => 'sku', 'sku' => Sku::query()->findOrFail($pack->sku_id), 'pack' => $pack];
        }

        $sku = Sku::query()->where('barcode_ean', $code)->first()
            ?? Sku::query()->where('sku_code', $code)->first();
        if ($sku !== null) {
            return ['kind' => 'sku', 'sku' => $sku, 'pack' => null];
        }

        if ($receipt !== null) {
            $bin = Bin::query()->where('location_id', $receipt->location_id)->where('code', $code)->first();
            if ($bin !== null) {
                return ['kind' => 'bin', 'bin' => $bin];
            }
        }

        Log::info('goods-in: unknown scanned code', ['code' => $code, 'receipt' => $receipt?->public_id]);

        return ['kind' => 'unknown', 'candidates' => $this->search($code)];
    }

    /**
     * @return list<Sku>
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return array_values(Sku::query()
            ->select('skus.*')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where(fn ($q) => $q->where('skus.sku_code', 'ilike', $like)->orWhere('products.name', 'ilike', $like))
            ->orderBy('skus.sku_code')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->all());
    }
}
