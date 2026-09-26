<?php

namespace App\Domain\Inventory;

use App\Models\Batch;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Doc 04 §5.1 eligibility and §5.2 strategy order. candidates() is the
 * SQL form used under lock by AllocationService; accepts() is the same
 * rule for rows already loaded, used by checkout preview.
 */
final class BatchEligibility
{
    public static function accepts(Sku $sku, Batch $batch, CarbonImmutable $today): bool
    {
        if ($batch->sku_id !== $sku->id || $batch->status !== 'active') {
            return false;
        }

        $minimumDays = $sku->min_remaining_shelf_life_days;
        if ($minimumDays !== null && $batch->expires_on === null) {
            return false;
        }

        return $batch->expires_on === null
            || $batch->expires_on->toDateString() >= $today->addDays((int) ($minimumDays ?? 0))->toDateString();
    }

    /**
     * Eligible batches of $sku with available stock at $locationId, in
     * strategy order. The FEFO ordering matches batches_fefo_idx (02 §10
     * Q15), so a LIMIT stops the index scan early with no sort.
     *
     * @return Builder<Batch>
     */
    public static function candidates(Sku $sku, int $locationId, CarbonImmutable $today): Builder
    {
        $cutoff = $today->addDays((int) ($sku->min_remaining_shelf_life_days ?? 0))->toDateString();

        $query = Batch::query()
            ->where('sku_id', $sku->id)
            ->where('status', 'active')
            ->when(
                $sku->min_remaining_shelf_life_days !== null,
                fn (Builder $q) => $q->where('expires_on', '>=', $cutoff),
                fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', $cutoff)),
            )
            ->whereHas('stockLevels', fn (Builder $q) => $q->where('location_id', $locationId)->where('available_base_qty', '>', 0));

        return match ($sku->allocation_strategy) {
            'fefo' => $query->orderByRaw('expires_on NULLS LAST')->orderBy('id'),
            'fifo' => $query->orderBy('id'),
            'lifo' => $query->orderByDesc('id'),
            default => throw new InvalidArgumentException("Sku {$sku->id} has no batch allocation strategy."),
        };
    }
}
