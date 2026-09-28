<?php

namespace App\Domain\Storefront;

use App\Domain\Inventory\StockLabels;
use App\Domain\Pricing\BulkPriceResolver;
use App\Domain\Pricing\PriceBreak;
use App\Models\Brand;
use App\Models\Media;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 05.15 §5.3 — everything the product page shows, in a fixed number of
 * queries: the product, its breadcrumb, images, active SKUs with their
 * sellable packs, one bulk price resolution with the full break table
 * (03 §8, 06 §9.1), stock labels, and related products.
 *
 * Customer-facing (CLAUDE.md invariant 9): no cost, no stock figure (05.15
 * §12 Q2). RRP is a trade selling aid (03 §11), sent to trade viewers only.
 */
final class ProductDetail
{
    private const COUNTRY = 'GB';

    public function __construct(
        private readonly BulkPriceResolver $prices = new BulkPriceResolver,
        private readonly StockLabels $stock = new StockLabels,
        private readonly ProductCards $cards = new ProductCards,
    ) {}

    /** @return array<string, mixed>|null null when there is no active product with that slug */
    public function bySlug(string $slug, ?int $companyId): ?array
    {
        $product = Product::query()->where('slug', $slug)->where('status', 'active')->whereNull('deleted_at')->first();
        if ($product === null) {
            return null;
        }
        $productId = (int) $product->id;
        $brandId = $product->getAttribute('brand_id');
        $brand = $brandId === null ? null : Brand::query()->where('status', 'active')->whereKey((int) $brandId)->first();
        $categoryId = $product->getAttribute('primary_category_id');
        $rrp = $product->getAttribute('rrp_minor');

        $skus = DB::table('skus')
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'public_id', 'sku_code', 'variant_label', 'default_pack_id', 'moq_base_qty', 'order_increment_base_qty', 'max_order_base_qty']);
        if ($skus->isEmpty()) {
            return null;
        }

        $skuIds = array_values($skus->map(fn ($s) => (int) $s->id)->all());
        $packs = $this->packs($skuIds);
        $resolution = $this->prices->resolveMany($skuIds, $companyId, 1, self::COUNTRY);
        $labels = $this->stock->forSkus($skuIds);

        $variants = [];
        foreach ($skus as $sku) {
            $skuId = (int) $sku->id;
            $price = $resolution->resolved[$skuId] ?? null;
            $skuPacks = $packs[$skuId] ?? [];

            $variants[] = [
                'id' => (string) $sku->public_id,
                'sku_code' => (string) $sku->sku_code,
                'label' => $sku->variant_label,
                'moq_base_qty' => (int) $sku->moq_base_qty,
                'increment_base_qty' => (int) $sku->order_increment_base_qty,
                'max_base_qty' => $sku->max_order_base_qty === null ? null : (int) $sku->max_order_base_qty,
                'packs' => array_map(fn (array $p) => ['code' => $p['code'], 'label' => $p['label'], 'base_units' => $p['base_units']], $skuPacks),
                'default_pack_code' => $this->defaultPackCode($skuPacks, $sku->default_pack_id === null ? null : (int) $sku->default_pack_id),
                'price' => $price === null ? null : [
                    'unit_net_e4' => $price->unitPriceE4,
                    'tax_rate_bp' => $price->taxRateBp,
                    'breaks' => array_map(fn (PriceBreak $b) => ['min_base_qty' => $b->minBaseQty, 'unit_net_e4' => $b->unitPriceE4], $resolution->breaks[$skuId] ?? []),
                ],
                'stock' => $labels[$skuId] ?? StockLabels::OUT_OF_STOCK,
            ];
        }

        $related = DB::table('products as p')
            ->where('p.primary_category_id', $categoryId)
            ->where('p.id', '<>', $productId)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->orderByRaw('p.published_at DESC NULLS LAST')
            ->orderByDesc('p.id')
            ->limit(8)
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'id' => (string) $product->getAttribute('public_id'),
            'name' => (string) $product->getAttribute('name'),
            'slug' => (string) $product->getAttribute('slug'),
            'brand' => $brand === null ? null : ['name' => (string) $brand->getAttribute('name'), 'slug' => (string) $brand->getAttribute('slug')],
            'short_description' => $product->getAttribute('short_description'),
            'description' => $product->getAttribute('description'),
            'specifications' => $this->specifications($product->getAttribute('specifications')),
            'meta_title' => $product->getAttribute('meta_title'),
            'meta_description' => $product->getAttribute('meta_description'),
            'rrp_minor' => $companyId !== null && $rrp !== null ? (int) $rrp : null,
            'breadcrumb' => $this->breadcrumb($categoryId === null ? null : (int) $categoryId),
            'images' => $this->images($productId, $skuIds),
            'variants' => $variants,
            'related' => $this->cards->cards(array_values($related), $companyId),
        ];
    }

    /**
     * The category and its ancestors, root first (`category_closure`).
     *
     * @return list<array{name: string, slug: string}>
     */
    public function breadcrumb(?int $categoryId): array
    {
        if ($categoryId === null) {
            return [];
        }

        return array_values(DB::table('category_closure as cc')
            ->join('categories as c', 'c.id', '=', 'cc.ancestor_id')
            ->where('cc.descendant_id', $categoryId)
            ->where('c.status', 'active')
            ->orderByDesc('cc.depth')
            ->get(['c.name', 'c.slug'])
            ->map(fn ($c) => ['name' => (string) $c->name, 'slug' => (string) $c->slug])
            ->all());
    }

    /**
     * @param  list<int>  $skuIds
     * @return list<array{url: string, alt: string|null}>
     */
    private function images(int $productId, array $skuIds): array
    {
        $images = [];
        Media::query()
            ->where('media_type', 'image')
            ->where(fn ($q) => $q->where('product_id', $productId)->orWhereIn('sku_id', $skuIds))
            ->orderBy('position')
            ->orderBy('id')
            ->get(['disk', 'path', 'alt_text'])
            ->each(function (Media $m) use (&$images) {
                try {
                    $images[] = ['url' => Storage::disk((string) $m->getAttribute('disk'))->url((string) $m->getAttribute('path')), 'alt' => $m->getAttribute('alt_text')];
                } catch (Throwable) {
                    // A disk that cannot build a URL shows no image, as Thumbnails does.
                }
            });

        return $images;
    }

    /**
     * @param  list<int>  $skuIds
     * @return array<int, list<array{id: int, code: string, label: string, base_units: int, is_default_sell: bool}>>
     */
    private function packs(array $skuIds): array
    {
        $bySku = [];
        DB::table('packs')
            ->whereIn('sku_id', $skuIds)
            ->where('is_sellable', true)
            ->orderBy('sku_id')
            ->orderBy('base_units')
            ->get(['id', 'sku_id', 'code', 'label', 'base_units', 'is_default_sell'])
            ->each(function ($p) use (&$bySku) {
                $bySku[(int) $p->sku_id][] = ['id' => (int) $p->id, 'code' => (string) $p->code, 'label' => (string) $p->label,
                    'base_units' => (int) $p->base_units, 'is_default_sell' => (bool) $p->is_default_sell];
            });

        return $bySku;
    }

    /**
     * The same order the cart uses when no pack is named (CartItemResolver):
     * `skus.default_pack_id`, then `is_default_sell`, then the smallest.
     *
     * @param  list<array{id: int, code: string, label: string, base_units: int, is_default_sell: bool}>  $packs
     */
    private function defaultPackCode(array $packs, ?int $defaultPackId): ?string
    {
        foreach ($packs as $pack) {
            if ($pack['id'] === $defaultPackId) {
                return $pack['code'];
            }
        }
        foreach ($packs as $pack) {
            if ($pack['is_default_sell']) {
                return $pack['code'];
            }
        }

        return $packs[0]['code'] ?? null;
    }

    /**
     * `products.specifications` as label/value rows; anything that is not a
     * flat object of scalars is left out rather than guessed at.
     *
     * @return list<array{label: string, value: string}>
     */
    private function specifications(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $label => $value) {
            if (is_string($label) && (is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                $rows[] = ['label' => $label, 'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value];
            }
        }

        return $rows;
    }
}
