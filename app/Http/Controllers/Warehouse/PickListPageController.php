<?php

namespace App\Http\Controllers\Warehouse;

use App\Domain\Warehouse\PickListGenerator;
use App\Domain\Warehouse\ShortPickReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Warehouse\PickListResource;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The picking screen (05.5 §5, §9) — `Warehouse/PickList`. Reads and
 * writes through `/api/v1/warehouse/shipments*`; these props are the
 * queue to start from and, with `?shipment=`, the pick list to resume.
 */
class PickListPageController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', Shipment::class);

        $id = $request->query('shipment');
        $shipment = is_string($id) ? Shipment::query()->where('public_id', $id)->first() : null;

        return Inertia::render('Warehouse/PickList', [
            'pick_list' => $shipment === null ? null : (new PickListResource((new PickListGenerator)->generate($shipment)))->resolve($request),
            'queue' => FulfilmentQueue::toPick(),
            'short_pick_reasons' => array_map(fn (ShortPickReason $r) => ['value' => $r->value, 'label' => $r->label()], ShortPickReason::cases()),
        ]);
    }
}
