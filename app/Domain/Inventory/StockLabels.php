<?php

namespace App\Domain\Inventory;

use App\Models\Sku;
use App\Models\StockLevel;

/**
 * 05.15 §5.3, §12 Q2 — what a public viewer is told about stock: a label,
 * and a figure only when it is small. That is the marketplace convention
 * (*Only 7 left*): the scarcity prompt helps the buyer, and a figure of 10
 * or fewer tells a competitor almost nothing, where the full stock position
 * would (05.13 §14). One aggregate query for any number of SKUs, summed
 * across locations and batches like /stock/availability.
 *
 *   - `in_stock`     available above the low-stock line, or not stock-tracked
 *   - `low_stock`    available, and at most SCARCITY_MAX or at or below a
 *                    positive reorder point; `left` is the figure only when
 *                    it is at most SCARCITY_MAX
 *   - `backorder`    none available, but the SKU allows backorders
 *   - `out_of_stock` none available
 */
final class StockLabels
{
    public const IN_STOCK = 'in_stock';

    public const LOW_STOCK = 'low_stock';

    public const BACKORDER = 'backorder';

    public const OUT_OF_STOCK = 'out_of_stock';

    /** At or below this many units, a public viewer is shown the figure. */
    public const SCARCITY_MAX = 10;

    /** Best first: a product shows its best SKU's label. */
    private const RANK = [self::IN_STOCK => 0, self::LOW_STOCK => 1, self::BACKORDER => 2, self::OUT_OF_STOCK => 3];

    /**
     * @param  list<int>  $skuIds
     * @return array<int, string> label by SKU id
     */
    public function forSkus(array $skuIds): array
    {
        return array_map(fn (array $s) => $s['label'], $this->detailed($skuIds));
    }

    /**
     * @param  list<int>  $skuIds
     * @return array<int, array{label: string, left: int|null}> by SKU id; `left` only when at most SCARCITY_MAX
     */
    public function detailed(array $skuIds): array
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

        $result = [];
        foreach ($skuIds as $skuId) {
            $sku = $skus->get($skuId);
            if ($sku === null) {
                continue;
            }

            $level = $levels->get($skuId);
            $available = $level === null ? 0 : (int) $level->getAttribute('available');
            $reorderPoint = $level === null ? 0 : (int) $level->getAttribute('reorder_point');

            $label = match (true) {
                ! $sku->is_stock_tracked => self::IN_STOCK,
                $available > 0 && ($available <= self::SCARCITY_MAX || ($reorderPoint > 0 && $available <= $reorderPoint)) => self::LOW_STOCK,
                $available > 0 => self::IN_STOCK,
                (bool) $sku->allow_backorder => self::BACKORDER,
                default => self::OUT_OF_STOCK,
            };

            $result[$skuId] = [
                'label' => $label,
                'left' => $sku->is_stock_tracked ? self::disclosable($available) : null,
            ];
        }

        return $result;
    }

    /** The figure a public viewer may see: only 1 to SCARCITY_MAX. */
    public static function disclosable(int $available): ?int
    {
        return $available > 0 && $available <= self::SCARCITY_MAX ? $available : null;
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
