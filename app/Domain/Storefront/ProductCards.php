<?php

namespace App\Domain\Storefront;

use App\Domain\Catalogue\Thumbnails;
use App\Domain\Inventory\StockLabels;
use App\Domain\Pricing\BulkPriceResolver;
use Illuminate\Support\Facades\DB;

/**
 * 05.15 §5.1 — product grid cards for the storefront: image, name, brand,
 * the "from" price and a stock label. A fixed number of queries per grid,
 * whatever its size (05.15 §2 rule 3): the products, their active SKUs,
 * thumbnails, one bulk price resolution (03 §8) and one stock aggregate.
 *
 * The "from" price is the lowest single-unit price across the product's
 * active SKUs, before volume breaks, sent net with its VAT rate. The page
 * shows it inc or ex VAT with the same integer arithmetic as the cart
 * (lib/cart/display.ts). Never cost (CLAUDE.md invariant 9): BulkPriceResolver
 * leaves `unitCostE4` null, and it is not read here anyway.
 */
final class ProductCards
{
    /** A public delivery is GB only (05.15 §6.1 rule G), so the rate is GB's. */
    private const COUNTRY = 'GB';

    public function __construct(
        private readonly BulkPriceResolver $prices = new BulkPriceResolver,
        private readonly StockLabels $stock = new StockLabels,
        private readonly Thumbnails $thumbnails = new Thumbnails,
    ) {}

    /**
     * Home page: featured products first, then the most recently published,
     * so a new store with nothing featured still shows a full row.
     *
     * @return list<array<string, mixed>>
     */
    public function homepage(?int $companyId, int $limit = 12): array
    {
        $ids = DB::table('products as p')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('skus as s')
                ->whereColumn('s.product_id', 'p.id')->where('s.status', 'active')->whereNull('s.deleted_at'))
            ->orderByDesc('p.is_featured')
            ->orderByRaw('p.published_at DESC NULLS LAST')
            ->orderByDesc('p.id')
            ->limit($limit)
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->cards(array_values($ids), $companyId);
    }

    /**
     * @param  list<int>  $productIds  in display order
     * @return list<array<string, mixed>>
     */
    public function cards(array $productIds, ?int $companyId): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->whereIn('p.id', $productIds)
            ->get(['p.id', 'p.public_id', 'p.name', 'p.slug', 'b.name as brand_name'])
            ->keyBy(fn ($p) => (int) $p->id);

        $skusByProduct = [];
        $skuRows = [];
        DB::table('skus')
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('product_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'public_id', 'product_id', 'default_pack_id', 'moq_base_qty'])
            ->each(function ($sku) use (&$skusByProduct, &$skuRows) {
                $skusByProduct[(int) $sku->product_id][] = (int) $sku->id;
                $skuRows[(int) $sku->id] = [
                    'public_id' => (string) $sku->public_id,
                    'default_pack_id' => $sku->default_pack_id === null ? null : (int) $sku->default_pack_id,
                    'moq_base_qty' => (int) $sku->moq_base_qty,
                ];
            });

        $skuIds = array_merge(...array_values($skusByProduct ?: [[]]));
        $resolution = $this->prices->resolveMany($skuIds, $companyId, 1, self::COUNTRY);
        $stock = $this->stock->detailed($skuIds);
        $thumbs = $this->thumbnails->lookup($skuIds, $productIds);
        $packs = $this->defaultPacks($skuRows);

        $cards = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            $skus = $skusByProduct[$productId] ?? [];
            if ($product === null || $skus === []) {
                continue;
            }

            $from = null;
            foreach ($skus as $skuId) {
                $price = $resolution->resolved[$skuId] ?? null;
                if ($price !== null && ($from === null || $price->unitPriceE4 < $from->unitPriceE4)) {
                    $from = $price;
                }
            }

            $thumbnail = null;
            foreach ($skus as $skuId) {
                $thumbnail ??= Thumbnails::pick($thumbs, $skuId, $productId);
            }

            $cards[] = [
                'id' => (string) $product->public_id,
                'name' => (string) $product->name,
                'slug' => (string) $product->slug,
                'brand' => $product->brand_name === null ? null : (string) $product->brand_name,
                'thumbnail_url' => $thumbnail,
                'price' => $from === null ? null : [
                    'unit_net_e4' => $from->unitPriceE4,
                    'tax_rate_bp' => $from->taxRateBp,
                    'varies' => count($skus) > 1,
                ],
                'stock' => StockLabels::best(array_map(fn (int $id) => $stock[$id]['label'] ?? StockLabels::OUT_OF_STOCK, $skus)),
                // "Only N left" for a single-SKU product; a range shows the label.
                'stock_left' => count($skus) === 1 ? ($stock[$skus[0]]['left'] ?? null) : null,
                'quick_add' => $this->quickAdd($skus, $skuRows, $packs, $from !== null, $stock),
            ];
        }

        return $cards;
    }

    /**
     * 05.15 §5.3a: one tap adds one default pack. Only for a single-SKU
     * product, priced, not out of stock, whose default pack alone meets
     * the MOQ; anything else needs the product page's choices.
     *
     * @param  list<int>  $skus
     * @param  array<int, array{public_id: string, default_pack_id: int|null, moq_base_qty: int}>  $skuRows
     * @param  array<int, array{code: string, base_units: int}>  $packs
     * @param  array<int, array{label: string, left: int|null}>  $stock
     * @return array{sku_id: string, pack_code: string}|null
     */
    private function quickAdd(array $skus, array $skuRows, array $packs, bool $priced, array $stock): ?array
    {
        if (count($skus) !== 1 || ! $priced) {
            return null;
        }
        $skuId = $skus[0];
        $pack = $packs[$skuId] ?? null;
        $label = $stock[$skuId]['label'] ?? StockLabels::OUT_OF_STOCK;
        $moq = $skuRows[$skuId]['moq_base_qty'];

        if ($pack === null || $label === StockLabels::OUT_OF_STOCK || $pack['base_units'] < $moq) {
            return null;
        }

        return ['sku_id' => $skuRows[$skuId]['public_id'], 'pack_code' => $pack['code']];
    }

    /**
     * Each SKU's default sell pack, in the cart's order (CartItemResolver):
     * `skus.default_pack_id`, then `is_default_sell`, then the smallest.
     *
     * @param  array<int, array{public_id: string, default_pack_id: int|null, moq_base_qty: int}>  $skuRows
     * @return array<int, array{code: string, base_units: int}>
     */
    private function defaultPacks(array $skuRows): array
    {
        if ($skuRows === []) {
            return [];
        }

        $bySku = [];
        DB::table('packs')->whereIn('sku_id', array_keys($skuRows))->where('is_sellable', true)
            ->orderBy('sku_id')->orderBy('base_units')
            ->get(['id', 'sku_id', 'code', 'base_units', 'is_default_sell'])
            ->each(function ($p) use (&$bySku) {
                $bySku[(int) $p->sku_id][] = $p;
            });

        $defaults = [];
        foreach ($bySku as $skuId => $packs) {
            $defaultId = $skuRows[$skuId]['default_pack_id'];
            $chosen = collect($packs)->first(fn ($p) => (int) $p->id === $defaultId)
                ?? collect($packs)->first(fn ($p) => (bool) $p->is_default_sell)
                ?? $packs[0];
            $defaults[$skuId] = ['code' => (string) $chosen->code, 'base_units' => (int) $chosen->base_units];
        }

        return $defaults;
    }
}
