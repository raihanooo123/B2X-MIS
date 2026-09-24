<?php

namespace App\Domain\Catalogue;

use App\Domain\Inventory\StockAvailabilityPredicate;
use App\Models\Media;
use App\Models\Pack;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Doc 05.1 §9 — the order pad's "one paginated catalogue query for the
 * rows themselves", plus the two batched lookups a row needs (sellable
 * packs, thumbnail). Three queries per page regardless of row count;
 * prices (Q-A/Q-B) and stock (Q-C) are fetched by the client via
 * /pricing/bulk-resolve and /stock/availability for the SKU ids returned
 * here.
 *
 * Rows are SKUs of active products, in `(product name, product id, sku
 * position, sku id)` order — 05.1 §9's `(name, id)` keyset, extended by
 * `skus_product_position_idx`'s order within a product. Keyset, never
 * OFFSET (02 §9 rule 8). The leading `products (name, id)` index does not
 * exist yet: it is drafted as a proposed 02 §10 amendment (Q23) awaiting
 * sign-off, so until then this query sorts without it.
 *
 * Filters (OrderPadFilters) narrow the same query rather than adding
 * one: search is 02 §5.4a's weighted `tsvector` match or'd with
 * substring/trigram matches on the product name and SKU code
 * (`products_search_gin`, `products_name_trgm`, `skus_code_trgm_idx`);
 * category takes the whole subtree through `category_closure` (Q3);
 * brand is `products_brand_active_idx`; "in stock only" is Inventory's
 * StockAvailabilityPredicate. Results keep the keyset order — search
 * narrows, it does not re-rank — so paging stays keyset (05.1 §9).
 *
 * The cursor is encrypted, not base64: the keyset tuple contains internal
 * ids, which 06 §2 never exposes, and encryption also makes a
 * hand-constructed cursor impossible (06 §15). It carries the number of
 * rows already shown, so row numbers continue across pages without a
 * COUNT.
 *
 * Customer-facing: no cost, no price — nothing here reads `sku_costs` or
 * price lists.
 */
final class OrderPadCatalogue
{
    public const PAGE_SIZE = 50;

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     start_row: int,
     *     next_cursor: string|null,
     * }
     */
    public function page(?string $cursor, OrderPadFilters $filters = new OrderPadFilters): array
    {
        $position = $this->decodeCursor($cursor, $filters->fingerprint());

        $query = DB::table('skus as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.status', 'active')
            ->whereNull('s.deleted_at')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->orderBy('p.name')
            ->orderBy('p.id')
            ->orderBy('s.position')
            ->orderBy('s.id')
            ->limit(self::PAGE_SIZE + 1)
            ->select([
                's.id', 's.public_id', 's.sku_code', 's.variant_label', 's.default_pack_id', 's.position',
                'p.id as product_id', 'p.name as product_name',
            ]);

        $this->applyFilters($query, $filters);

        if ($position !== null) {
            $query->whereRaw('(p.name, p.id, s.position, s.id) > (?, ?, ?, ?)', [
                $position['name'], $position['product_id'], $position['sku_position'], $position['sku_id'],
            ]);
        }

        $skus = $query->get();
        $hasMore = $skus->count() > self::PAGE_SIZE;
        $skus = $skus->take(self::PAGE_SIZE)->values();

        $skuIds = array_values($skus->pluck('id')->map(fn ($id) => (int) $id)->all());
        $productIds = array_values($skus->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all());

        $packsBySku = $this->sellablePacks($skuIds);
        $thumbnails = $this->thumbnails($skuIds, $productIds);

        $startRow = ($position['rows_before'] ?? 0) + 1;

        $rows = [];
        foreach ($skus as $sku) {
            $skuId = (int) $sku->id;
            $packs = $packsBySku[$skuId] ?? [];

            $rows[] = [
                'sku_id' => (string) $sku->public_id,
                'sku_code' => (string) $sku->sku_code,
                'variant_label' => $sku->variant_label,
                'product_name' => (string) $sku->product_name,
                'thumbnail_url' => $thumbnails['sku'][$skuId] ?? $thumbnails['product'][(int) $sku->product_id] ?? null,
                'packs' => array_map(fn (array $p) => [
                    'code' => $p['code'],
                    'label' => $p['label'],
                    'pack_level' => $p['pack_level'],
                    'base_units' => $p['base_units'],
                ], $packs),
                'default_pack_code' => $this->defaultPackCode($packs, $sku->default_pack_id === null ? null : (int) $sku->default_pack_id),
            ];
        }

        $last = $skus->last();

        return [
            'rows' => $rows,
            'start_row' => $startRow,
            'next_cursor' => $hasMore && $last !== null ? $this->encodeCursor([
                'name' => (string) $last->product_name,
                'product_id' => (int) $last->product_id,
                'sku_position' => (int) $last->position,
                'sku_id' => (int) $last->id,
                'rows_before' => $startRow - 1 + count($rows),
                'filters' => $filters->fingerprint(),
            ]) : null,
        ];
    }

    /**
     * Options for the category and brand filters: active categories in
     * tree order (`path` is zero-padded, so lexicographic order is tree
     * order — CategoryPath) with their depth for indenting, and active
     * brands by name (`brands_active_idx`).
     *
     * @return array{categories: list<array{slug: string, name: string, depth: int}>, brands: list<array{slug: string, name: string}>}
     */
    public function facets(): array
    {
        $categories = DB::table('categories')
            ->where('status', 'active')
            ->orderBy('path')
            ->orderBy('position')
            ->orderBy('name')
            ->get(['slug', 'name', 'depth']);

        $brands = DB::table('brands')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['slug', 'name']);

        return [
            'categories' => array_values($categories->map(fn ($c) => ['slug' => (string) $c->slug, 'name' => (string) $c->name, 'depth' => (int) $c->depth])->all()),
            'brands' => array_values($brands->map(fn ($b) => ['slug' => (string) $b->slug, 'name' => (string) $b->name])->all()),
        ];
    }

    private function applyFilters(Builder $query, OrderPadFilters $filters): void
    {
        if ($filters->search !== null) {
            $term = $filters->search;
            $contains = '%'.addcslashes($term, '%_\\').'%';

            $query->where(function (Builder $q) use ($term, $contains) {
                $q->whereRaw("p.search_vector @@ websearch_to_tsquery('english', ?)", [$term])
                    ->orWhere('p.name', 'ilike', $contains)
                    ->orWhereRaw('p.name % ?', [$term])
                    ->orWhere('s.sku_code', 'ilike', $contains);
            });
        }

        if ($filters->categorySlug !== null) {
            $query->whereIn('p.primary_category_id', fn (Builder $q) => $q
                ->select('cc.descendant_id')
                ->from('category_closure as cc')
                ->join('categories as c', 'c.id', '=', 'cc.ancestor_id')
                ->where('c.slug', $filters->categorySlug));
        }

        if ($filters->brandSlug !== null) {
            $query->whereIn('p.brand_id', fn (Builder $q) => $q
                ->select('b.id')
                ->from('brands as b')
                ->where('b.slug', $filters->brandSlug));
        }

        if ($filters->inStockOnly) {
            StockAvailabilityPredicate::whereInStock($query, 's');
        }
    }

    /**
     * 05.1 §4.2: sellable packs only, ascending by `base_units`
     * (`packs_sku_sellable_idx`, Q5).
     *
     * @param  list<int>  $skuIds
     * @return array<int, list<array{id: int, code: string, label: string, pack_level: string, base_units: int, is_default_sell: bool}>>
     */
    private function sellablePacks(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $bySku = [];
        Pack::query()
            ->whereIn('sku_id', $skuIds)
            ->where('is_sellable', true)
            ->orderBy('sku_id')
            ->orderBy('base_units')
            ->get(['id', 'sku_id', 'code', 'label', 'pack_level', 'base_units', 'is_default_sell'])
            ->each(function (Pack $pack) use (&$bySku) {
                $bySku[$pack->sku_id][] = [
                    'id' => (int) $pack->id,
                    'code' => $pack->code,
                    'label' => $pack->label,
                    'pack_level' => (string) $pack->getAttribute('pack_level'),
                    'base_units' => $pack->base_units,
                    'is_default_sell' => (bool) $pack->getAttribute('is_default_sell'),
                ];
            });

        return $bySku;
    }

    /**
     * `skus.default_pack_id` first, then `is_default_sell`, then the
     * smallest sellable pack — the same order CartItemResolver uses, so
     * the pad preselects the pack an add without `pack_code` would use.
     *
     * @param  list<array{id: int, code: string, label: string, pack_level: string, base_units: int, is_default_sell: bool}>  $packs
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
     * 05.1 §4.2: "From `media` for the SKU, else the product, else a
     * neutral placeholder" — lowest `position` image wins in each case
     * (`media_sku_idx` / `media_product_idx`). A disk that cannot build a
     * URL (e.g. s3 unconfigured locally) yields null → placeholder, as
     * ProductResource's thumbnail column already does.
     *
     * @param  list<int>  $skuIds
     * @param  list<int>  $productIds
     * @return array{sku: array<int, string>, product: array<int, string>}
     */
    private function thumbnails(array $skuIds, array $productIds): array
    {
        $result = ['sku' => [], 'product' => []];

        if ($skuIds === []) {
            return $result;
        }

        $media = Media::query()
            ->where('media_type', 'image')
            ->where(fn ($q) => $q->whereIn('sku_id', $skuIds)->orWhere(fn ($q) => $q->whereNull('sku_id')->whereIn('product_id', $productIds)))
            ->orderBy('position')
            ->orderBy('id')
            ->get(['sku_id', 'product_id', 'disk', 'path']);

        foreach ($media as $item) {
            $url = $this->url((string) $item->getAttribute('disk'), (string) $item->getAttribute('path'));
            if ($url === null) {
                continue;
            }

            $skuId = $item->getAttribute('sku_id');
            if ($skuId !== null) {
                $result['sku'][(int) $skuId] ??= $url;
            } else {
                $result['product'][(int) $item->getAttribute('product_id')] ??= $url;
            }
        }

        return $result;
    }

    private function url(string $disk, string $path): ?string
    {
        try {
            return Storage::disk($disk)->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{name: string, product_id: int, sku_position: int, sku_id: int, rows_before: int, filters: string}  $position
     */
    private function encodeCursor(array $position): string
    {
        return Crypt::encryptString((string) json_encode($position));
    }

    /**
     * An unreadable cursor restarts from the first page rather than
     * erroring: it can only come from a stale link (e.g. after an APP_KEY
     * rotation), never from a client building one. A cursor minted under
     * different filters restarts too (OrderPadFilters::fingerprint()).
     *
     * @return array{name: string, product_id: int, sku_position: int, sku_id: int, rows_before: int}|null
     */
    private function decodeCursor(?string $cursor, string $filtersFingerprint): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($decoded)
            || ! is_string($decoded['name'] ?? null)
            || ! is_int($decoded['product_id'] ?? null)
            || ! is_int($decoded['sku_position'] ?? null)
            || ! is_int($decoded['sku_id'] ?? null)
            || ! is_int($decoded['rows_before'] ?? null)
            || ($decoded['filters'] ?? null) !== $filtersFingerprint) {
            return null;
        }

        return [
            'name' => $decoded['name'],
            'product_id' => $decoded['product_id'],
            'sku_position' => $decoded['sku_position'],
            'sku_id' => $decoded['sku_id'],
            'rows_before' => $decoded['rows_before'],
        ];
    }
}
