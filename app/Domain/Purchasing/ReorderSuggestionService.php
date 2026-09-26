<?php

namespace App\Domain\Purchasing;

use App\Models\ReorderSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * 05.7 §10 (amended and signed off 2026-09-26): advisory reorder
 * suggestions, one row per (SKU, location), computed set-based in one
 * query so the Filament table can page, sort and filter in SQL.
 *
 * Scope is exactly what stock_levels_reorder_idx holds — a (SKU,
 * location) with a reorder point set on any of its rows (§10.1). Rows
 * are folded per (SKU, location) because a batch-tracked SKU has one
 * stock_levels row per batch, and incoming_base_qty sits on the NULL-
 * batch row. Stock on an open PO counts as cover.
 *
 * All arithmetic is integer (§10.4): the cover test is cross-multiplied
 * rather than divided, and the only divisions are the final ceilings.
 * The one non-integer value is the outlier cutoff from percentile_cont,
 * compared against a quantity — never money.
 */
final class ReorderSuggestionService
{
    public const SALES_WINDOW_DAYS_KEY = 'purchasing.reorder.sales_window_days';

    public const OUTLIER_PERCENTILE_KEY = 'purchasing.reorder.outlier_percentile';

    public const SAFETY_DAYS_KEY = 'purchasing.reorder.safety_days';

    public const REVIEW_PERIOD_DAYS_KEY = 'purchasing.reorder.review_period_days';

    public const DEFAULT_LEAD_TIME_DAYS_KEY = 'purchasing.reorder.default_lead_time_days';

    public const DEFAULT_SALES_WINDOW_DAYS = 90;

    public const DEFAULT_OUTLIER_PERCENTILE = 95;

    public const DEFAULT_SAFETY_DAYS = 7;

    public const DEFAULT_REVIEW_PERIOD_DAYS = 14;

    public const DEFAULT_LEAD_TIME_DAYS = 30;

    /** @return Builder<ReorderSuggestion> */
    public function query(?CarbonImmutable $asOf = null): Builder
    {
        $asOf ??= CarbonImmutable::now();
        $bindings = [];

        $settings = implode(",\n", array_map(function (array $setting) use (&$bindings): string {
            [$key, $default, $alias] = $setting;
            array_push($bindings, $key, $default);

            // 02 §2.7: location scope beats global, then the code default.
            return "COALESCE((SELECT c.value_int FROM system_configurations c
                        WHERE c.config_key = ?
                          AND ((c.scope = 'location' AND c.location_id = l.id) OR c.scope = 'global')
                        ORDER BY c.scope = 'location' DESC LIMIT 1), ?)::bigint AS {$alias}";
        }, [
            [self::SALES_WINDOW_DAYS_KEY, self::DEFAULT_SALES_WINDOW_DAYS, 'window_days'],
            [self::OUTLIER_PERCENTILE_KEY, self::DEFAULT_OUTLIER_PERCENTILE, 'outlier_percentile'],
            [self::SAFETY_DAYS_KEY, self::DEFAULT_SAFETY_DAYS, 'safety_days'],
            [self::REVIEW_PERIOD_DAYS_KEY, self::DEFAULT_REVIEW_PERIOD_DAYS, 'review_period_days'],
            [self::DEFAULT_LEAD_TIME_DAYS_KEY, self::DEFAULT_LEAD_TIME_DAYS, 'default_lead_time_days'],
        ]));

        array_push($bindings, $asOf->toIso8601String(), $asOf->toIso8601String());

        $sql = <<<SQL
            WITH opted_in AS (
                SELECT DISTINCT sku_id, location_id
                FROM stock_levels
                WHERE reorder_point_base_qty > 0
            ),
            levels AS (
                SELECT sl.sku_id, sl.location_id,
                       SUM(sl.available_base_qty)::bigint     AS available_base_qty,
                       SUM(sl.incoming_base_qty)::bigint      AS incoming_base_qty,
                       MAX(sl.reorder_point_base_qty)::bigint AS reorder_point_base_qty,
                       MAX(sl.reorder_qty_base_qty)::bigint   AS reorder_qty_base_qty
                FROM opted_in o
                JOIN stock_levels sl ON sl.sku_id = o.sku_id AND sl.location_id = o.location_id
                JOIN skus s          ON s.id = sl.sku_id
                JOIN locations l     ON l.id = sl.location_id
                WHERE s.status = 'active' AND s.is_stock_tracked AND s.deleted_at IS NULL
                  AND l.is_sellable
                GROUP BY sl.sku_id, sl.location_id
            ),
            raw_settings AS (
                SELECT l.id AS location_id,
                {$settings}
                FROM locations l
                WHERE l.id IN (SELECT location_id FROM levels)
            ),
            settings AS (
                SELECT location_id,
                       GREATEST(window_days, 1)                    AS window_days,
                       LEAST(GREATEST(outlier_percentile, 0), 100) AS outlier_percentile,
                       GREATEST(safety_days, 0)                    AS safety_days,
                       GREATEST(review_period_days, 0)             AS review_period_days,
                       GREATEST(default_lead_time_days, 0)         AS default_lead_time_days
                FROM raw_settings
            ),
            sales_lines AS (
                SELECT lv.sku_id, lv.location_id, sln.dispatched_base_qty::bigint AS qty
                FROM levels lv
                JOIN settings st        ON st.location_id = lv.location_id
                JOIN shipment_lines sln ON sln.sku_id = lv.sku_id
                JOIN shipments sh       ON sh.id = sln.shipment_id
                WHERE sh.location_id = lv.location_id
                  AND sh.status = 'dispatched'
                  AND sh.dispatched_at >  ?::timestamptz - make_interval(days => st.window_days::int)
                  AND sh.dispatched_at <= ?::timestamptz
            ),
            cutoffs AS (
                SELECT sl.sku_id, sl.location_id,
                       percentile_cont(st.outlier_percentile / 100.0) WITHIN GROUP (ORDER BY sl.qty) AS cutoff
                FROM sales_lines sl
                JOIN settings st ON st.location_id = sl.location_id
                GROUP BY sl.sku_id, sl.location_id, st.outlier_percentile
            ),
            sales AS (
                SELECT sl.sku_id, sl.location_id, SUM(sl.qty)::bigint AS sold
                FROM sales_lines sl
                JOIN cutoffs c ON c.sku_id = sl.sku_id AND c.location_id = sl.location_id
                WHERE sl.qty <= c.cutoff
                GROUP BY sl.sku_id, sl.location_id
            ),
            last_po AS (
                SELECT DISTINCT ON (pol.sku_id) pol.sku_id, po.supplier_id, pol.pack_base_units
                FROM purchase_order_lines pol
                JOIN purchase_orders po ON po.id = pol.purchase_order_id
                WHERE pol.sku_id IN (SELECT sku_id FROM levels)
                  AND po.status NOT IN ('draft', 'cancelled')
                ORDER BY pol.sku_id, po.ordered_at DESC NULLS LAST, po.id DESC, pol.id DESC
            ),
            inputs AS (
                SELECT lv.*, lp.supplier_id,
                       lv.available_base_qty + lv.incoming_base_qty                     AS position_base_qty,
                       COALESCE(sa.sold, 0)                                             AS sold_base_qty,
                       st.window_days, st.safety_days, st.review_period_days,
                       COALESCE(sup.lead_time_days, st.default_lead_time_days)::bigint AS lead_time_days,
                       COALESCE(lp.pack_base_units, dp.base_units, 1)::bigint          AS pack_base_units
                FROM levels lv
                JOIN settings st       ON st.location_id = lv.location_id
                LEFT JOIN sales sa     ON sa.sku_id = lv.sku_id AND sa.location_id = lv.location_id
                LEFT JOIN last_po lp   ON lp.sku_id = lv.sku_id
                LEFT JOIN suppliers sup ON sup.id = lp.supplier_id
                LEFT JOIN packs dp     ON dp.sku_id = lv.sku_id AND dp.is_default_sell
            ),
            evaluated AS (
                SELECT i.*,
                       i.position_base_qty <= i.reorder_point_base_qty AS below_reorder_point,
                       (i.sold_base_qty > 0 AND i.position_base_qty * i.window_days
                            <= i.sold_base_qty * (i.lead_time_days + i.safety_days)) AS cover_short,
                       GREATEST(
                           i.reorder_qty_base_qty,
                           (i.sold_base_qty * (i.lead_time_days + i.review_period_days) + i.window_days - 1)
                               / i.window_days - i.position_base_qty,
                           0
                       ) AS unrounded_base_qty
                FROM inputs i
            )
            SELECT e.sku_id || ':' || e.location_id AS id,
                   e.sku_id, e.location_id, e.supplier_id,
                   e.available_base_qty, e.incoming_base_qty, e.position_base_qty,
                   e.reorder_point_base_qty, e.reorder_qty_base_qty,
                   e.sold_base_qty, e.window_days AS sales_window_days, e.lead_time_days, e.pack_base_units,
                   CASE WHEN e.sold_base_qty > 0
                        THEN (e.position_base_qty * e.window_days) / e.sold_base_qty END AS cover_days,
                   e.below_reorder_point, e.cover_short,
                   ((e.unrounded_base_qty + e.pack_base_units - 1) / e.pack_base_units) * e.pack_base_units
                       AS suggested_base_qty
            FROM evaluated e
            WHERE e.below_reorder_point OR e.cover_short
            SQL;

        return ReorderSuggestion::query()->fromRaw("({$sql}) AS reorder_suggestions", $bindings);
    }
}
