<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Query\Builder;

/**
 * "Is this SKU available to order from stock?" as a SQL predicate, for
 * listings that filter on it (05.1's "in stock only") inside their own
 * single query — the rule stays owned by Inventory rather than being
 * re-derived by each caller from `stock_levels`.
 *
 * Same figure /stock/availability reports (StockController): available
 * base quantity summed over every location and batch, > 0. A SKU that
 * is not stock-tracked is always available — there is no figure to run
 * out of. Backorderable SKUs at zero are *not* in stock; they are on
 * backorder, which is what the pad shows them as.
 *
 * `stock_levels` is a projection (CLAUDE.md invariant 5); this reads it
 * the way every availability display does, and never locks.
 */
final class StockAvailabilityPredicate
{
    /**
     * @param  Builder  $query  a query already joined to `skus`
     * @param  string  $skuAlias  the alias `skus` is joined under
     */
    public static function whereInStock(Builder $query, string $skuAlias): void
    {
        $query->where(function (Builder $q) use ($skuAlias) {
            $q->where("{$skuAlias}.is_stock_tracked", false)
                ->orWhereRaw(
                    "(SELECT COALESCE(SUM(sl.available_base_qty), 0) FROM stock_levels sl WHERE sl.sku_id = {$skuAlias}.id) > 0"
                );
        });
    }
}
