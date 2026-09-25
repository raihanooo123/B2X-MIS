<?php

namespace App\Http\Controllers\Warehouse;

use App\Domain\Warehouse\PickListGenerator;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Warehouse\PickListResource;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dispatch screen (05.5 §7, §9) — `Warehouse/Dispatch`. Shipments
 * picked and waiting to leave, and with `?shipment=` the one being
 * dispatched: what it carries and what the order will still be owed.
 */
class DispatchPageController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', Shipment::class);

        $id = $request->query('shipment');
        $shipment = is_string($id) ? Shipment::query()->where('public_id', $id)->first() : null;

        return Inertia::render('Warehouse/Dispatch', [
            'shipment' => $shipment === null ? null : (new PickListResource((new PickListGenerator)->generate($shipment)))->resolve($request),
            'queue' => FulfilmentQueue::toDispatch(),
        ]);
    }
}
