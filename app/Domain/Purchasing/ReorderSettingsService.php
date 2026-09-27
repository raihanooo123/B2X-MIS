<?php

namespace App\Domain\Purchasing;

use App\Models\Location;
use App\Models\ReorderSetting;
use App\Models\Sku;
use App\Models\StockLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 05.7 §10.7 (signed off 2026-09-27): purchasing sets the reorder point
 * and reorder quantity per (SKU, location). Planning, not a stock change
 * — no movement is written and no quantity column is touched.
 *
 * The value lives on the NULL-batch row, the one row per (SKU, location)
 * for every tracking mode (02 §23.5); batch rows' copies are zeroed in
 * the same transaction so §10.1's MAX fold reads exactly what was set.
 */
final class ReorderSettingsService
{
    private const MAX_BASE_QTY = 2147483647;

    public function set(int $skuId, int $locationId, int $reorderPointBaseQty, int $reorderQtyBaseQty): void
    {
        $errors = [];
        foreach (['reorder_point_base_qty' => $reorderPointBaseQty, 'reorder_qty_base_qty' => $reorderQtyBaseQty] as $field => $value) {
            if ($value < 0 || $value > self::MAX_BASE_QTY) {
                $errors[$field] = 'Enter a whole number of units, 0 or more.';
            }
        }

        $sku = Sku::query()->find($skuId);
        if ($sku === null || $sku->status !== 'active' || ! $sku->is_stock_tracked) {
            $errors['sku_id'] = 'Reorder levels can only be set for an active, stock-tracked SKU.';
        }
        if (! Location::query()->whereKey($locationId)->where('is_sellable', true)->exists()) {
            $errors['location_id'] = 'Reorder levels can only be set at a sellable location.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($skuId, $locationId, $reorderPointBaseQty, $reorderQtyBaseQty) {
            $now = CarbonImmutable::now();

            // Create the NULL-batch row first if missing: it sorts first in
            // the global lock order, so taking it before the batch rows
            // keeps §11.1's order (CLAUDE.md invariant 6).
            DB::statement(<<<'SQL'
                INSERT INTO stock_levels (sku_id, location_id, batch_id, updated_at)
                VALUES (?, ?, NULL, ?)
                ON CONFLICT ON CONSTRAINT stock_levels_identity_uq DO NOTHING
            SQL, [$skuId, $locationId, $now]);

            StockLevel::query()
                ->where('sku_id', $skuId)
                ->where('location_id', $locationId)
                ->orderByRaw('batch_id NULLS FIRST')
                ->lockForUpdate()
                ->get();

            StockLevel::identity($skuId, $locationId)->toBase()->update([
                'reorder_point_base_qty' => $reorderPointBaseQty,
                'reorder_qty_base_qty' => $reorderQtyBaseQty,
                'updated_at' => $now,
            ]);
            StockLevel::query()
                ->where('sku_id', $skuId)
                ->where('location_id', $locationId)
                ->whereNotNull('batch_id')
                ->where(fn ($q) => $q->where('reorder_point_base_qty', '<>', 0)->orWhere('reorder_qty_base_qty', '<>', 0))
                ->toBase()
                ->update(['reorder_point_base_qty' => 0, 'reorder_qty_base_qty' => 0, 'updated_at' => $now]);
        });
    }

    /**
     * Every active, stock-tracked SKU at every sellable location, with its
     * current values folded as §10.1 reads them.
     *
     * @return Builder<ReorderSetting>
     */
    public function query(): Builder
    {
        $sql = <<<'SQL'
            SELECT s.id || ':' || l.id AS id,
                   s.id AS sku_id, l.id AS location_id,
                   COALESCE(SUM(sl.available_base_qty), 0)::bigint     AS available_base_qty,
                   COALESCE(SUM(sl.incoming_base_qty), 0)::bigint      AS incoming_base_qty,
                   COALESCE(MAX(sl.reorder_point_base_qty), 0)::bigint AS reorder_point_base_qty,
                   COALESCE(MAX(sl.reorder_qty_base_qty), 0)::bigint   AS reorder_qty_base_qty
            FROM skus s
            CROSS JOIN locations l
            LEFT JOIN stock_levels sl ON sl.sku_id = s.id AND sl.location_id = l.id
            WHERE s.status = 'active' AND s.is_stock_tracked AND s.deleted_at IS NULL
              AND l.is_sellable
            GROUP BY s.id, l.id
            SQL;

        return ReorderSetting::query()->fromRaw("({$sql}) AS reorder_settings");
    }
}
