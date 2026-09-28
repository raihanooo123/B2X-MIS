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
        DB::table('skus')
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('product_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'product_id'])
            ->each(function ($sku) use (&$skusByProduct) {
                $skusByProduct[(int) $sku->product_id][] = (int) $sku->id;
            });

        $skuIds = array_merge(...array_values($skusByProduct ?: [[]]));
        $resolution = $this->prices->resolveMany($skuIds, $companyId, 1, self::COUNTRY);
        $labels = $this->stock->forSkus($skuIds);
        $thumbs = $this->thumbnails->lookup($skuIds, $productIds);

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
                'stock' => StockLabels::best(array_values(array_intersect_key($labels, array_flip($skus)))),
            ];
        }

        return $cards;
    }
}
