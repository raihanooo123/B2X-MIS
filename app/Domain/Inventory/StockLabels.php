<?php

namespace App\Domain\Inventory;

use App\Models\Sku;
use App\Models\StockLevel;

/**
 * 05.15 §5.3, §12 Q2 — the stock *label* a public viewer sees, never the
 * figure: exact quantities are a trade view (05.1 §4.3) and would show a
 * competitor the stock position. One aggregate query for any number of
 * SKUs, summed across locations and batches like /stock/availability.
 *
 *   - `in_stock`     available above the reorder point, or not stock-tracked
 *   - `low_stock`    available, at or below a positive reorder point
 *   - `backorder`    none available, but the SKU allows backorders
 *   - `out_of_stock` none available
 */
final class StockLabels
{
    public const IN_STOCK = 'in_stock';

    public const LOW_STOCK = 'low_stock';

    public const BACKORDER = 'backorder';

    public const OUT_OF_STOCK = 'out_of_stock';

    /** Best first: a product shows its best SKU's label. */
    private const RANK = [self::IN_STOCK => 0, self::LOW_STOCK => 1, self::BACKORDER => 2, self::OUT_OF_STOCK => 3];

    /**
     * @param  list<int>  $skuIds
     * @return array<int, string> label by SKU id
     */
    public function forSkus(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        $skus = Sku::query()->whereIn('id', $skuIds)->get(['id', 'is_stock_tracked', 'allow_backorder'])->keyBy('id');
        $levels = StockLevel::query()
            ->whereIn('sku_id', $skuIds)
            ->selectRaw('sku_id, COALESCE(SUM(available_base_qty), 0) AS available, COALESCE(SUM(reorder_point_base_qty), 0) AS reorder_point')
            ->groupBy('sku_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->getAttribute('sku_id'));

        $labels = [];
        foreach ($skuIds as $skuId) {
            $sku = $skus->get($skuId);
            if ($sku === null) {
                continue;
            }

            $level = $levels->get($skuId);
            $available = $level === null ? 0 : (int) $level->getAttribute('available');
            $reorderPoint = $level === null ? 0 : (int) $level->getAttribute('reorder_point');

            $labels[$skuId] = match (true) {
                ! $sku->is_stock_tracked => self::IN_STOCK,
                $available > 0 && $reorderPoint > 0 && $available <= $reorderPoint => self::LOW_STOCK,
                $available > 0 => self::IN_STOCK,
                (bool) $sku->allow_backorder => self::BACKORDER,
                default => self::OUT_OF_STOCK,
            };
        }

        return $labels;
    }

    /** @param  list<string>  $labels */
    public static function best(array $labels): string
    {
        $best = self::OUT_OF_STOCK;
        foreach ($labels as $label) {
            if (self::RANK[$label] < self::RANK[$best]) {
                $best = $label;
            }
        }

        return $best;
    }
}
