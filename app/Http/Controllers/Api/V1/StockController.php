<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockAvailabilityRequest;
use App\Models\Sku;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;

/**
 * Doc 06 §9.4. "Batch and serial detail are not exposed to
 * customer-facing callers" — this controller never selects `batch_id`
 * or reads `stock_serials` at all; `available_base_qty`/
 * `incoming_base_qty` are summed across every location and batch for
 * the SKU, collapsing that detail before it ever reaches a response
 * array.
 */
class StockController extends Controller
{
    public function availability(StockAvailabilityRequest $request): JsonResponse
    {
        $publicIds = $request->skuPublicIds();

        $skusByPublicId = Sku::query()
            ->whereIn('public_id', $publicIds)
            ->get(['id', 'public_id', 'is_stock_tracked', 'allow_backorder'])
            ->keyBy('public_id');

        $internalIds = $skusByPublicId->pluck('id')->map(fn ($id) => (int) $id)->all();

        // One aggregate query regardless of how many SKUs were
        // requested — never a per-SKU lookup.
        $aggregatesBySkuId = StockLevel::query()
            ->whereIn('sku_id', $internalIds)
            ->selectRaw('sku_id, COALESCE(SUM(available_base_qty), 0) as available_base_qty, COALESCE(SUM(incoming_base_qty), 0) as incoming_base_qty')
            ->groupBy('sku_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->sku_id);

        $data = [];
        foreach ($publicIds as $publicId) {
            $sku = $skusByPublicId->get($publicId);

            if ($sku === null) {
                $data[] = [
                    'sku_id' => $publicId,
                    'error' => [
                        'code' => 'not_found',
                        'message' => "No SKU found for id {$publicId}.",
                    ],
                ];

                continue;
            }

            $aggregate = $aggregatesBySkuId->get((int) $sku->id);
            $availableBaseQty = $aggregate !== null ? (int) $aggregate->available_base_qty : 0;
            $incomingBaseQty = $aggregate !== null ? (int) $aggregate->incoming_base_qty : 0;

            $data[] = [
                'sku_id' => $publicId,
                'available_base_qty' => $availableBaseQty,
                'is_stock_tracked' => (bool) $sku->is_stock_tracked,
                'allow_backorder' => (bool) $sku->allow_backorder,
                // `expected_on` has no source column anywhere in the
                // schema (02 §7.3's incoming_base_qty carries no
                // associated date) — returned honestly as null rather
                // than fabricated. Flagged in the session report as a
                // doc 06 §9.4 / doc 02 gap worth an amendment.
                'incoming' => $incomingBaseQty > 0
                    ? ['base_qty' => $incomingBaseQty, 'expected_on' => null]
                    : null,
            ];
        }

        return response()->json(['data' => $data]);
    }
}
