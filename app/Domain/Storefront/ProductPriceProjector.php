<?php

namespace App\Domain\Storefront;

use App\Domain\Pricing\BulkPriceResolver;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\ResolvedPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 02 §29 — keeps `product_price_projections`, the storefront's price sort
 * key, in step with the catalogue.
 *
 * Each active product's row is exactly the "from" price its card shows a
 * logged-out visitor (ProductCards::cards): every active SKU resolved by
 * BulkPriceResolver with no company, at `base_qty = 1`, now, GB; the SKU
 * with the lowest **net** unit price (first by position on a tie, as the
 * card iterates); its gross by the display formula (05.15 §4.3). An
 * active product with no base price gets a row of NULLs, so it still
 * lists — last — in a price sort. Inactive or deleted products have no row.
 *
 * `stale_after` is the next moment that could change a row without any
 * write: the end of the base list in force, the start of a scheduled one,
 * or a dated GB VAT change for one of the product's tax classes.
 *
 * A sort key, never a price: nothing charges or displays from this table
 * (CLAUDE.md invariant 3). A wrong row orders a listing wrongly until the
 * next refresh, so drift is corrected, not merely reported (02 §29.4).
 */
final class ProductPriceProjector
{
    private const COUNTRY = 'GB';

    private const CHUNK = 1000;

    public function __construct(
        private readonly BulkPriceResolver $prices = new BulkPriceResolver,
    ) {}

    /**
     * Recompute these products' rows (insert, update or delete). Idempotent.
     *
     * @param  list<int>  $productIds
     * @return int rows whose stored values differed from the recomputed ones
     */
    public function refresh(array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }
        $productIds = array_values(array_unique($productIds));
        $now = CarbonImmutable::now();

        $active = array_values(DB::table('products')->whereIn('id', $productIds)->where('status', 'active')->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id) => (int) $id)->all());

        $rows = $this->compute($active, $now);
        $existing = DB::table('product_price_projections')->whereIn('product_id', $productIds)->get()->keyBy(fn ($r) => (int) $r->product_id);

        $drift = 0;
        foreach ($rows as $productId => $row) {
            $old = $existing->get($productId);
            if ($old === null || self::key((array) $old) !== self::key($row)) {
                $drift++;
            }
        }
        $gone = array_values(array_diff($productIds, $active));
        $drift += DB::table('product_price_projections')->whereIn('product_id', $gone)->count();

        DB::transaction(function () use ($rows, $gone): void {
            if ($gone !== []) {
                DB::table('product_price_projections')->whereIn('product_id', $gone)->delete();
            }
            if ($rows !== []) {
                DB::table('product_price_projections')->upsert(
                    array_values($rows),
                    ['product_id'],
                    ['from_sku_id', 'price_list_id', 'from_unit_net_e4', 'tax_rate_bp', 'from_unit_gross_e4', 'stale_after', 'refreshed_at'],
                );
            }
        });

        return $drift;
    }

    /**
     * Every active product, in chunks by id; rows for products no longer
     * active are removed. The nightly rebuild (02 §29.4).
     *
     * @return int rows that had drifted from the recomputed values
     */
    public function refreshAll(): int
    {
        $drift = 0;
        $after = 0;
        do {
            $ids = array_values(DB::table('products')->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)
                ->pluck('id')->map(fn ($id) => (int) $id)->all());
            if ($ids !== []) {
                $drift += $this->refresh($ids);
                $after = $ids[count($ids) - 1];
            }
        } while (count($ids) === self::CHUNK);

        // Rows whose product has gone altogether (deleted outright).
        $drift += DB::table('product_price_projections as pp')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('products as p')->whereColumn('p.id', 'pp.product_id'))
            ->delete();

        return $drift;
    }

    /** Rows whose `stale_after` has passed (02 §29.4, every 5 minutes). */
    public function refreshStale(): int
    {
        $ids = DB::table('product_price_projections')->where('stale_after', '<=', now())
            ->orderBy('product_id')->limit(self::CHUNK * 10)->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->refresh($chunk);
        }

        return count($ids);
    }

    /**
     * Products with an active SKU in one of these tax classes (a GB VAT change).
     *
     * @param  list<int>  $taxClassIds
     */
    public function refreshTaxClasses(array $taxClassIds): void
    {
        $ids = DB::table('skus')->whereIn('tax_class_id', $taxClassIds)->distinct()->orderBy('product_id')
            ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->refresh($chunk);
        }
    }

    /**
     * @param  list<int>  $productIds  active products
     * @return array<int, array<string, mixed>> product id => row
     */
    private function compute(array $productIds, CarbonImmutable $now): array
    {
        if ($productIds === []) {
            return [];
        }

        $skusByProduct = [];
        $taxClassBySku = [];
        DB::table('skus')->whereIn('product_id', $productIds)->where('status', 'active')->whereNull('deleted_at')
            ->orderBy('product_id')->orderBy('position')->orderBy('id')
            ->get(['id', 'product_id', 'tax_class_id'])
            ->each(function ($sku) use (&$skusByProduct, &$taxClassBySku): void {
                $skusByProduct[(int) $sku->product_id][] = (int) $sku->id;
                $taxClassBySku[(int) $sku->id] = $sku->tax_class_id === null ? null : (int) $sku->tax_class_id;
            });

        $skuIds = array_merge(...array_values($skusByProduct ?: [[]]));
        $resolution = $this->prices->resolveMany($skuIds, null, 1, self::COUNTRY, $now);
        $baseBoundary = $this->nextBaseListBoundary($now);
        $taxBoundaries = $this->nextTaxBoundaries(array_values(array_unique(array_filter($taxClassBySku, fn ($v) => $v !== null))), $now);

        $rows = [];
        foreach ($productIds as $productId) {
            /** @var ResolvedPrice|null $from */
            $from = null;
            $stale = $baseBoundary;
            foreach ($skusByProduct[$productId] ?? [] as $skuId) {
                $price = $resolution->resolved[$skuId] ?? null;
                // A price with no list cannot be traced; it is treated as no price.
                if ($price !== null && $price->priceListId !== null && ($from === null || $price->unitPriceE4 < $from->unitPriceE4)) {
                    $from = $price;
                }
                $class = $taxClassBySku[$skuId] ?? null;
                if ($class !== null && isset($taxBoundaries[$class])) {
                    $stale = $stale === null || $taxBoundaries[$class]->lessThan($stale) ? $taxBoundaries[$class] : $stale;
                }
            }

            $rows[$productId] = [
                'product_id' => $productId,
                'from_sku_id' => $from?->skuId,
                'price_list_id' => $from?->priceListId,
                'from_unit_net_e4' => $from?->unitPriceE4,
                'tax_rate_bp' => $from?->taxRateBp,
                // 05.15 §4.3: the gross the card prints, integer arithmetic only.
                'from_unit_gross_e4' => $from === null ? null : Money::roundHalfUpDiv($from->unitPriceE4 * (10000 + $from->taxRateBp), 10000),
                'stale_after' => $stale?->toIso8601String(),
                'refreshed_at' => $now->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * The values a row is compared on, normalised to int-or-null.
     *
     * @param  array<string, mixed>  $row
     * @return list<int|null>
     */
    private static function key(array $row): array
    {
        return array_map(fn (string $column) => $row[$column] === null ? null : (int) $row[$column],
            ['from_sku_id', 'price_list_id', 'from_unit_net_e4', 'tax_rate_bp', 'from_unit_gross_e4']);
    }

    /** The end of the active base list in force, or the start of the next scheduled one, whichever is first. */
    private function nextBaseListBoundary(CarbonImmutable $now): ?CarbonImmutable
    {
        $at = $now->toIso8601String();
        $value = DB::table('price_lists')
            ->where('scope', 'base')->where('status', 'active')->where('currency', 'GBP')
            ->selectRaw('MIN(CASE WHEN lower(validity) > ?::timestamptz THEN lower(validity)
                                  WHEN upper(validity) > ?::timestamptz AND NOT upper_inf(validity) THEN upper(validity) END) AS next', [$at, $at])
            ->value('next');

        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    /**
     * Per tax class, the next dated GB rate change after now.
     *
     * @param  list<int>  $taxClassIds
     * @return array<int, CarbonImmutable>
     */
    private function nextTaxBoundaries(array $taxClassIds, CarbonImmutable $now): array
    {
        if ($taxClassIds === []) {
            return [];
        }
        $at = $now->toIso8601String();
        $out = [];
        DB::table('tax_rates')->whereIn('tax_class_id', $taxClassIds)->where('country_code', self::COUNTRY)
            ->groupBy('tax_class_id')
            ->selectRaw('tax_class_id, MIN(CASE WHEN lower(validity) > ?::timestamptz THEN lower(validity)
                                               WHEN upper(validity) > ?::timestamptz AND NOT upper_inf(validity) THEN upper(validity) END) AS next', [$at, $at])
            ->get()
            ->each(function ($row) use (&$out): void {
                if ($row->next !== null) {
                    $out[(int) $row->tax_class_id] = CarbonImmutable::parse((string) $row->next);
                }
            });

        return $out;
    }
}
