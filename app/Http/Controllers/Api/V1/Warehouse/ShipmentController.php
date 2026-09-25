<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Domain\Warehouse\BatchSubstitutionService;
use App\Domain\Warehouse\DispatchDetails;
use App\Domain\Warehouse\DispatchService;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Domain\Warehouse\FulfilmentRules;
use App\Domain\Warehouse\PickConfirmationService;
use App\Domain\Warehouse\PickListGenerator;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Warehouse\DispatchShipmentRequest;
use App\Http\Requests\Api\V1\Warehouse\OpenShipmentRequest;
use App\Http\Requests\Api\V1\Warehouse\PickLineRequest;
use App\Http\Requests\Api\V1\Warehouse\ScanSerialRequest;
use App\Http\Requests\Api\V1\Warehouse\ShortPickRequest;
use App\Http\Requests\Api\V1\Warehouse\SubstituteBatchRequest;
use App\Http\Resources\Api\V1\Warehouse\PickListResource;
use App\Http\Support\Idempotency;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StockAllocation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Doc 06 §8 — `/warehouse/shipments`, `/shipments/{id}` (the pick list),
 * and the pick-line actions (05.5 §5): serial scans, confirm, short pick,
 * batch substitution; and `/shipments/{id}/dispatch` (05.5 §7, idempotency
 * required, 06 §6).
 *
 * Thin: identifiers become rows here — order number, location code, the
 * shipment's public id, a pick line's `(line_no, batch_code)` — and every
 * rule is the Warehouse services'. Their refusals become 06 §4's envelope.
 * Every action answers with the refreshed pick list, because one action
 * can change several lines (a short pick re-plans; a substitution moves a
 * line to another batch).
 */
class ShipmentController extends Controller
{
    public function __construct(
        private readonly PickListGenerator $pickLists = new PickListGenerator,
        private readonly PickConfirmationService $picking = new PickConfirmationService,
        private readonly BatchSubstitutionService $substitution = new BatchSubstitutionService,
        private readonly DispatchService $dispatch = new DispatchService,
    ) {}

    public function store(OpenShipmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Shipment::class);

        $orderId = Order::query()->where('order_number', $request->orderNumber())->value('id')
            ?? throw $this->unprocessable('order_not_found', "No order {$request->orderNumber()}.", 'order_number');
        $locationId = ($request->locationCode() === null
            ? Location::query()->where('is_default', true)->value('id')
            : Location::query()->where('code', $request->locationCode())->value('id'))
            ?? throw $this->unprocessable('location_not_found', 'No such location.', 'location_code');

        $shipment = $this->refusals(fn () => $this->pickLists->open((int) $orderId, (int) $locationId));

        return $this->pickList($request, $shipment, 201)
            ->header('Location', "/api/v1/warehouse/shipments/{$shipment->public_id}");
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $shipment = $this->shipment($id);
        Gate::authorize('view', $shipment);

        return $this->pickList($request, $shipment);
    }

    /** 05.5 §5.2: blocked, not warned — a refused scan is a 422, never a soft warning. */
    public function scanSerial(ScanSerialRequest $request, string $id): JsonResponse
    {
        $shipment = $this->shipment($id);
        Gate::authorize('update', $shipment);

        $result = $this->refusals(fn () => $this->picking->scanSerial($shipment, $request->serialNumber()));

        return $this->pickList($request, $shipment, 200, ['serial_number' => $result['serial']->serial_number, 'replayed' => $result['replayed']]);
    }

    public function confirm(PickLineRequest $request, string $id): JsonResponse
    {
        $shipment = $this->shipment($id);
        Gate::authorize('update', $shipment);

        $allocation = $this->allocation($shipment, $request->lineNo(), $request->batchCode());
        $this->refusals(fn () => $this->picking->confirm($shipment, $allocation->id));

        return $this->pickList($request, $shipment);
    }

    public function shortPick(ShortPickRequest $request, string $id): JsonResponse
    {
        $shipment = $this->shipment($id);
        Gate::authorize('update', $shipment);

        $allocation = $this->allocation($shipment, $request->lineNo(), $request->batchCode());
        $packBaseUnits = (int) $allocation->orderLine()->value('pack_base_units');
        $picked = $request->pickedPackQty() * max(1, $packBaseUnits) + $request->pickedLooseUnits();

        $outcome = $this->refusals(fn () => $this->picking->shortPick($shipment, $allocation->id, $picked, $request->reason(), $this->actorId($request)));

        return $this->pickList($request, $shipment, 200, [
            'picked_base_qty' => $outcome->pickedBaseQty,
            'shortfall_base_qty' => $outcome->shortfallBaseQty,
            'replanned_base_qty' => $outcome->replannedBaseQty,
            'backordered_base_qty' => $outcome->backorderedBaseQty(),
        ]);
    }

    public function substitute(SubstituteBatchRequest $request, string $id): JsonResponse
    {
        $shipment = $this->shipment($id);
        Gate::authorize('update', $shipment);

        $allocation = $this->allocation($shipment, $request->lineNo(), $request->batchCode());
        $batchId = Batch::query()->where('sku_id', $allocation->sku_id)->where('batch_code', $request->newBatchCode())->value('id')
            ?? throw $this->unprocessable('batch_not_found', "No batch {$request->newBatchCode()} of this SKU.", 'new_batch_code');

        $this->refusals(fn () => $this->substitution->substitute($shipment, $allocation->id, (int) $batchId, $request->reason(), $this->actorId($request)));

        return $this->pickList($request, $shipment);
    }

    public function dispatch(DispatchShipmentRequest $request, string $id): JsonResponse
    {
        $shipment = $this->shipment($id);
        Gate::authorize('update', $shipment);

        return Idempotency::run($request, 'dispatch:'.$shipment->public_id, function () use ($request, $shipment) {
            if ($shipment->fulfilment_type === 'delivery' && $request->carrier() === null && $shipment->status !== 'dispatched') {
                throw $this->unprocessable('carrier_required', 'Record the carrier for a delivery.', 'carrier');
            }

            $outcome = $this->refusals(fn () => $this->dispatch->dispatch($shipment, new DispatchDetails(
                carrier: $request->carrier(),
                trackingNumber: $request->trackingNumber(),
                parcelCount: $request->parcelCount(),
                totalWeightG: $request->totalWeightG(),
                note: $request->note(),
                actorUserId: $this->actorId($request),
            )));

            return $this->pickList($request, $outcome->shipment->refresh(), 200, [
                'replayed' => $outcome->replayed,
                'order_fully_dispatched' => $outcome->orderFullyDispatched,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function pickList(Request $request, Shipment $shipment, int $status = 200, array $extra = []): JsonResponse
    {
        $data = (new PickListResource($this->pickLists->generate($shipment->refresh())))->resolve($request);

        return response()->json(['data' => $data + ($extra === [] ? [] : ['result' => $extra])], $status);
    }

    private function shipment(string $publicId): Shipment
    {
        return Shipment::query()->where('public_id', $publicId)->first()
            ?? throw new ApiException(404, 'not_found', 'Not found.');
    }

    /** A pick line by `(line_no, batch_code)` within the shipment's scope. */
    private function allocation(Shipment $shipment, int $lineNo, ?string $batchCode): StockAllocation
    {
        return FulfilmentRules::activeAllocations($shipment->order_id, $shipment->location_id)
            ->where('order_lines.line_no', $lineNo)
            ->when(
                $batchCode === null,
                fn ($q) => $q->whereNull('stock_allocations.batch_id'),
                fn ($q) => $q->whereIn('stock_allocations.batch_id', Batch::query()->select('id')->where('batch_code', $batchCode)),
            )
            ->first()
            ?? throw $this->unprocessable('line_not_on_shipment', "Line {$lineNo}".($batchCode === null ? '' : " batch {$batchCode}").' is not on this pick list.', 'line_no');
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }

    private function unprocessable(string $code, string $message, string $field): ApiException
    {
        return new ApiException(422, $code, $message, [['field' => $field, 'code' => $code, 'message' => $message]]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function refusals(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (FulfilmentRejectedException $e) {
            throw new ApiException($e->status, $e->errorCode, $e->getMessage(), $e->details());
        }
    }
}
