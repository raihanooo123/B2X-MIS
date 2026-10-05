<?php

namespace App\Domain\Storefront;

use App\Domain\Inventory\StockAvailabilityPredicate;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 05.15 §5.1–5.2 — the product grid behind the category and search pages:
 * one row per active product with at least one active SKU, filtered by
 * search, category subtree, brand and "in stock only".
 *
 * Keyset pagination, never OFFSET (02 §9 rule 8): the cursor is the last
 * row's sort key plus `id`, encrypted like the order pad's (it carries an
 * internal id, which 06 §2 never exposes). Sorts: name A–Z, newest, and
 * price low–high / high–low (02 §29, guests and public customers only —
 * the caller decides who may use them). Price sorts read the sort key in
 * `product_price_projections` (ProductPriceProjector), never a price: the
 * cards still resolve every price they show (CLAUDE.md invariant 3).
 *
 * Price paging is two-phase (02 §29.5): priced products in price order,
 * then unpriced ones by id, so an unpriced product is always last in either
 * direction and one index serves both. The cursor carries the phase.
 *
 * Search uses the same predicates as the order pad (OrderPadCatalogue):
 * the weighted `tsvector`, a substring or trigram match on the name, and a
 * substring match on any active SKU's code.
 */
final class StorefrontCatalogue
{
    public const PAGE_SIZE = 24;

    public const SORTS = ['name', 'newest', 'price_asc', 'price_desc'];

    /** 02 §29: offered to guests and public customers only (Q-P1). */
    public const PRICE_SORTS = ['price_asc', 'price_desc'];

    /**
     * @param  string  $priceMode  `gross` or `net`: which price a price sort orders by, the one the viewer sees
     * @return array{product_ids: list<int>, next_cursor: string|null}
     */
    public function page(StorefrontFilters $filters, ?string $cursor, int $pageSize = self::PAGE_SIZE, string $priceMode = 'gross'): array
    {
        if (in_array($filters->sort, self::PRICE_SORTS, true)) {
            return $this->pricePage($filters, $cursor, $pageSize, $priceMode === 'net' ? 'net' : 'gross');
        }

        $query = DB::table('products as p')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('skus as s')
                ->whereColumn('s.product_id', 'p.id')->where('s.status', 'active')->whereNull('s.deleted_at'))
            ->limit($pageSize + 1)
            ->select(['p.id', 'p.name', 'p.published_at']);

        $this->applyFilters($query, $filters);

        $position = $this->decodeCursor($cursor, $filters);
        if ($filters->sort === 'newest') {
            // NULLS LAST: an unpublished product sorts after every published one.
            $query->orderByRaw('p.published_at DESC NULLS LAST')->orderByDesc('p.id');
            if ($position !== null) {
                $position['key'] === null
                    ? $query->whereNull('p.published_at')->where('p.id', '<', $position['id'])
                    : $query->where(fn (Builder $q) => $q->where('p.published_at', '<', $position['key'])
                        ->orWhereNull('p.published_at')
                        ->orWhere(fn (Builder $q) => $q->where('p.published_at', $position['key'])->where('p.id', '<', $position['id'])));
            }
        } else {
            $query->orderBy('p.name')->orderBy('p.id');
            if ($position !== null) {
                $query->whereRaw('(p.name, p.id) > (?, ?)', [$position['key'], $position['id']]);
            }
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $pageSize;
        $rows = $rows->take($pageSize)->values();
        $last = $rows->last();

        return [
            'product_ids' => array_values($rows->map(fn ($r) => (int) $r->id)->all()),
            'next_cursor' => $hasMore && $last !== null ? Crypt::encryptString((string) json_encode([
                'key' => $filters->sort === 'newest' ? $last->published_at : $last->name,
                'id' => (int) $last->id,
                'filters' => $filters->fingerprint(),
            ])) : null,
        ];
    }

    /**
     * 02 §29.5: phase 1, priced products by (price, id) ascending or
     * descending; phase 2, unpriced products by id ascending. A page that
     * runs out of phase 1 is filled from the start of phase 2.
     *
     * @return array{product_ids: list<int>, next_cursor: string|null}
     */
    private function pricePage(StorefrontFilters $filters, ?string $cursor, int $pageSize, string $mode): array
    {
        $column = $mode === 'net' ? 'pp.from_unit_net_e4' : 'pp.from_unit_gross_e4';
        $descending = $filters->sort === 'price_desc';
        $position = $this->decodePriceCursor($cursor, $filters, $mode);
        $want = $pageSize + 1;

        $rows = [];
        if ($position === null || $position['phase'] === 'priced') {
            $priced = $this->base($filters)
                ->join('product_price_projections as pp', 'pp.product_id', '=', 'p.id')
                ->whereNotNull($column)
                ->addSelect(DB::raw("{$column} AS sort_price"))
                ->limit($want);
            $descending
                ? $priced->orderByDesc($column)->orderByDesc('p.id')
                : $priced->orderBy($column)->orderBy('p.id');
            if ($position !== null) {
                $priced->whereRaw("({$column}, p.id) ".($descending ? '<' : '>').' (?, ?)', [$position['price'], $position['id']]);
            }
            foreach ($priced->get() as $row) {
                $rows[] = ['id' => (int) $row->id, 'phase' => 'priced', 'price' => (int) $row->sort_price];
            }
        }

        if (count($rows) < $want) {
            $unpriced = $this->base($filters)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('product_price_projections as pp')
                    ->whereColumn('pp.product_id', 'p.id')->whereNotNull($column))
                ->orderBy('p.id')
                ->limit($want - count($rows));
            if ($position !== null && $position['phase'] === 'unpriced') {
                $unpriced->where('p.id', '>', $position['id']);
            }
            foreach ($unpriced->get() as $row) {
                $rows[] = ['id' => (int) $row->id, 'phase' => 'unpriced', 'price' => null];
            }
        }

        $hasMore = count($rows) > $pageSize;
        $rows = array_slice($rows, 0, $pageSize);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'product_ids' => array_column($rows, 'id'),
            'next_cursor' => $hasMore && $last !== null ? Crypt::encryptString((string) json_encode([
                'phase' => $last['phase'],
                'price' => $last['price'],
                'id' => $last['id'],
                'mode' => $mode,
                'filters' => $filters->fingerprint(),
            ])) : null,
        ];
    }

    /** The grid's products: active, with an active SKU, filtered. */
    private function base(StorefrontFilters $filters): Builder
    {
        $query = DB::table('products as p')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('skus as s')
                ->whereColumn('s.product_id', 'p.id')->where('s.status', 'active')->whereNull('s.deleted_at'))
            ->select(['p.id']);
        $this->applyFilters($query, $filters);

        return $query;
    }

    /** @return array{phase: string, price: int|null, id: int}|null */
    private function decodePriceCursor(?string $cursor, StorefrontFilters $filters, string $mode): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true);
        } catch (DecryptException) {
            return null;
        }

        // A cursor minted under other filters, or another display mode, restarts from the first page.
        if (! is_array($decoded) || ! is_int($decoded['id'] ?? null) || ! in_array($decoded['phase'] ?? null, ['priced', 'unpriced'], true)
            || ($decoded['phase'] === 'priced' && ! is_int($decoded['price'] ?? null))
            || ($decoded['mode'] ?? null) !== $mode
            || ($decoded['filters'] ?? null) !== $filters->fingerprint()) {
            return null;
        }

        return ['phase' => $decoded['phase'], 'price' => $decoded['phase'] === 'priced' ? $decoded['price'] : null, 'id' => $decoded['id']];
    }

    /**
     * Brands present in the unfiltered-by-brand result, for the brand filter.
     *
     * @return list<array{slug: string, name: string}>
     */
    public function brands(StorefrontFilters $filters): array
    {
        $query = DB::table('products as p')
            ->join('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->where('b.status', 'active')
            ->distinct()
            ->orderBy('b.name')
            ->select(['b.slug', 'b.name']);
        $this->applyFilters($query, $filters->withoutBrand());

        return array_values($query->get()->map(fn ($b) => ['slug' => (string) $b->slug, 'name' => (string) $b->name])->all());
    }

    private function applyFilters(Builder $query, StorefrontFilters $filters): void
    {
        if ($filters->search !== null) {
            $term = $filters->search;
            $contains = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw("p.search_vector @@ websearch_to_tsquery('english', ?)", [$term])
                ->orWhere('p.name', 'ilike', $contains)
                ->orWhereRaw('p.name % ?', [$term])
                ->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->from('skus as sc')
                    ->whereColumn('sc.product_id', 'p.id')->where('sc.status', 'active')->where('sc.sku_code', 'ilike', $contains)));
        }

        if ($filters->categoryId !== null) {
            $query->whereIn('p.primary_category_id', fn (Builder $q) => $q
                ->select('cc.descendant_id')->from('category_closure as cc')->where('cc.ancestor_id', $filters->categoryId));
        }

        if ($filters->brandSlug !== null) {
            $query->whereIn('p.brand_id', fn (Builder $q) => $q->select('b2.id')->from('brands as b2')->where('b2.slug', $filters->brandSlug));
        }

        if ($filters->inStockOnly) {
            $query->whereExists(function (Builder $q) {
                $q->selectRaw('1')->from('skus as si')->whereColumn('si.product_id', 'p.id')->where('si.status', 'active');
                StockAvailabilityPredicate::whereInStock($q, 'si');
            });
        }
    }

    /** @return array{key: string|null, id: int}|null */
    private function decodeCursor(?string $cursor, StorefrontFilters $filters): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true);
        } catch (DecryptException) {
            return null;
        }

        // A cursor minted under other filters restarts from the first page.
        if (! is_array($decoded) || ! is_int($decoded['id'] ?? null)
            || ! (is_string($decoded['key'] ?? null) || ($decoded['key'] ?? null) === null)
            || ($decoded['filters'] ?? null) !== $filters->fingerprint()) {
            return null;
        }

        return ['key' => $decoded['key'], 'id' => $decoded['id']];
    }
}
