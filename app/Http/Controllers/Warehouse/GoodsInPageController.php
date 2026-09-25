<?php

namespace App\Http\Controllers\Warehouse;

use App\Domain\Warehouse\ExpiryPolicy;
use App\Domain\Warehouse\VarianceReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Warehouse\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The goods-in screen (05.5 §4.2, §9) — `Warehouse/GoodsIn`. The page
 * reads and writes through `/api/v1/warehouse/*`; these props are what it
 * needs before the first scan: open receipts to resume, the locations a
 * manual receipt can go into, the variance reasons, and the receipt named
 * by `?receipt=` when resuming one.
 */
class GoodsInPageController extends Controller
{
    private const OPEN_RECEIPTS_SHOWN = 50;

    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', GoodsReceipt::class);

        $receiptId = $request->query('receipt');
        $receipt = is_string($receiptId) ? GoodsReceipt::query()->where('public_id', $receiptId)->first() : null;

        $open = GoodsReceipt::query()
            ->where('status', 'open')
            ->with(['purchaseOrder:id,po_number', 'container:id,container_ref', 'location:id,code'])
            ->withCount('lines')
            ->orderByDesc('opened_at')
            ->limit(self::OPEN_RECEIPTS_SHOWN)
            ->get();

        return Inertia::render('Warehouse/GoodsIn', [
            'receipt' => $receipt === null ? null : (new GoodsReceiptResource($receipt))->resolve($request),
            'open_receipts' => $open->map(fn (GoodsReceipt $r) => [
                'id' => $r->public_id,
                'source' => $r->source,
                'reference' => $r->purchaseOrder->po_number ?? $r->container?->container_ref,
                'location_code' => $r->location?->code,
                'opened_at' => $r->opened_at->toIso8601String(),
                'line_count' => (int) $r->getAttribute('lines_count'),
            ])->values()->all(),
            'locations' => Location::query()->orderBy('code')->get(['code', 'name'])->map(fn (Location $l) => ['code' => $l->code, 'name' => $l->name])->values()->all(),
            'variance_reasons' => array_map(fn (VarianceReason $r) => ['value' => $r->value, 'label' => $r->label()], VarianceReason::cases()),
            'default_horizon_days' => (new ExpiryPolicy)->horizonDays(null),
            'can_receive' => Gate::allows('create', GoodsReceipt::class),
        ]);
    }
}
