<?php

namespace App\Http\Resources\Api\V1\Warehouse;

use App\Domain\Warehouse\StocktakeLineReview;
use App\Domain\Warehouse\StocktakeService;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stocktake for the counting screen (05.5 §8).
 *
 * **Blind counting:** while a blind stocktake is `open`, no system figure
 * is included — the operative counts rather than confirms. In `review`,
 * each line carries what it would post: the level now, the level as it
 * stood when counted (02 §24.1), the variance, and missing and found
 * serials by number. Once `posted`, the stored figures.
 *
 * No price or cost (invariant 9). SKUs by public id, batches by code.
 *
 * @property Stocktake $resource
 */
class StocktakeResource extends JsonResource
{
    public function __construct(Stocktake $stocktake)
    {
        parent::__construct($stocktake);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stocktake = $this->resource;
        $location = Location::query()->find($stocktake->location_id, ['code', 'name']);
        $showSystem = ! $stocktake->is_blind || $stocktake->status !== 'open';

        $lines = StocktakeLine::query()->where('stocktake_id', $stocktake->id)->with('serials')->orderBy('id')->get();
        $skus = Sku::query()->with('product:id,name')->whereIn('id', $lines->pluck('sku_id'))->get()->keyBy('id');
        $batches = Batch::query()->whereIn('id', $lines->pluck('batch_id')->filter())->get(['id', 'batch_code', 'expires_on'])->keyBy('id');

        /** @var array<int, StocktakeLineReview> $reviews */
        $reviews = [];
        if ($stocktake->status === 'review') {
            foreach ((new StocktakeService)->review($stocktake) as $review) {
                $reviews[$review->line->id] = $review;
            }
        }

        return [
            'id' => $stocktake->public_id,
            'status' => $stocktake->status,
            'is_blind' => $stocktake->is_blind,
            'location' => ['code' => $location?->code, 'name' => $location?->name],
            'started_at' => $stocktake->started_at->toIso8601String(),
            'posted_at' => $stocktake->posted_at?->toIso8601String(),
            'lines' => $lines->map(function (StocktakeLine $line) use ($skus, $batches, $reviews, $stocktake, $showSystem) {
                $sku = $skus->get($line->sku_id);
                $batch = $line->batch_id === null ? null : $batches->get($line->batch_id);
                $review = $reviews[$line->id] ?? null;

                $row = [
                    'line_id' => $line->id,
                    'sku_id' => $sku?->public_id,
                    'sku_code' => $sku?->sku_code,
                    'name' => $sku?->product?->name,
                    'tracking_mode' => $sku?->tracking_mode,
                    'batch_code' => $batch?->batch_code,
                    'expires_on' => $batch?->expires_on?->toDateString(),
                    'counted_base_qty' => $line->counted_base_qty,
                    'counted_at' => $line->counted_at->toIso8601String(),
                    'serials' => $line->serials->sortBy('serial_number')->pluck('serial_number')->values()->all(),
                ];

                if ($showSystem && $stocktake->status === 'open') {
                    $row['system_base_qty'] = (int) (StockLevel::identity($line->sku_id, $stocktake->location_id, $line->batch_id)->value('on_hand_base_qty') ?? 0);
                }
                if ($review !== null) {
                    $row['review'] = [
                        'on_hand_now' => $review->onHandNow,
                        'expected_at_count' => $review->expectedAtCount,
                        'variance_base_qty' => $review->variance(),
                        'missing_serials' => $review->missingSerials,
                        'found_serials' => $review->foundSerials,
                        'blockers' => $review->blockers,
                    ];
                }
                if ($stocktake->status === 'posted') {
                    $row['posted'] = [
                        'expected_base_qty' => $line->expected_base_qty,
                        'variance_base_qty' => $line->variance_base_qty,
                        'reason_code' => $line->reason_code,
                    ];
                }

                return $row;
            })->values()->all(),
        ];
    }
}
