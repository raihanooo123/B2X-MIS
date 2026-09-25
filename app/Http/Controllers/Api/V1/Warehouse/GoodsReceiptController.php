<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Domain\Warehouse\Exceptions\GoodsInRejectedException;
use App\Domain\Warehouse\ExpiryPolicy;
use App\Domain\Warehouse\GoodsInService;
use App\Domain\Warehouse\ReceiptSource;
use App\Domain\Warehouse\ReceiveLine;
use App\Domain\Warehouse\ScanResolver;
use App\Domain\Warehouse\VarianceDecision;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Warehouse\CloseGoodsReceiptRequest;
use App\Http\Requests\Api\V1\Warehouse\GoodsInLookupRequest;
use App\Http\Requests\Api\V1\Warehouse\OpenGoodsReceiptRequest;
use App\Http\Requests\Api\V1\Warehouse\ReceiveGoodsRequest;
use App\Http\Resources\Api\V1\Warehouse\GoodsReceiptResource;
use App\Http\Support\Idempotency;
use App\Models\Bin;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Doc 06 §8 — `/warehouse/receipts`, `/receipts/{id}/lines` (idempotency
 * required, 06 §6), `/receipts/{id}/close`, and `/warehouse/lookup` for
 * scanning (05.5 §9).
 *
 * Thin: identifiers are turned into rows here — public ids, PO number and
 * line number, pack code, bin code — and everything else is
 * GoodsInService. Its refusals become 06 §4's envelope with their own
 * stable codes.
 *
 * Receiving is idempotent twice over. Idempotency::run() replays a
 * response for 24 hours from the cache; GoodsInService replays from
 * `goods_receipt_lines_idempotency_uq` for ever after (02 §23.2), since
 * the Idempotency-Key is the line's `client_token`.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsInService $goodsIn = new GoodsInService,
        private readonly ScanResolver $scanner = new ScanResolver,
    ) {}

    public function store(OpenGoodsReceiptRequest $request): JsonResponse
    {
        Gate::authorize('create', GoodsReceipt::class);

        $source = $request->source();
        $reference = $request->reference();

        $receipt = $this->refusals(fn () => $this->goodsIn->open(
            $source,
            $source === ReceiptSource::PurchaseOrder ? $this->idOrReject(PurchaseOrder::query()->where('po_number', $reference)->value('id'), 'purchase_order_not_found', "No purchase order {$reference}.") : null,
            $source === ReceiptSource::Container ? $this->idOrReject(Container::query()->where('container_ref', $reference)->value('id'), 'container_not_found', "No container {$reference}.") : null,
            $source === ReceiptSource::Manual ? $this->idOrReject(Location::query()->where('code', $request->locationCode())->value('id'), 'location_not_found', 'No location with that code.') : null,
            $this->actorId($request),
        ));

        return (new GoodsReceiptResource($receipt))->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/warehouse/receipts/{$receipt->public_id}");
    }

    public function show(string $id): JsonResponse
    {
        $receipt = $this->receipt($id);
        Gate::authorize('view', $receipt);

        return (new GoodsReceiptResource($receipt))->response();
    }

    public function storeLine(ReceiveGoodsRequest $request, string $id): JsonResponse
    {
        $receipt = $this->receipt($id);
        Gate::authorize('update', $receipt);

        return Idempotency::run($request, 'goods-in:'.$receipt->public_id, function () use ($request, $receipt) {
            $sku = Sku::query()->where('public_id', $request->skuPublicId())->first()
                ?? throw new ApiException(422, 'sku_not_found', 'No SKU with that id.', [['field' => 'sku_id', 'code' => 'sku_not_found', 'message' => 'No SKU with that id.']]);

            $packId = Pack::query()->where('sku_id', $sku->id)->where('code', $request->packCode())->value('id')
                ?? throw new ApiException(422, 'pack_not_for_sku', "{$sku->sku_code} has no pack {$request->packCode()}.", [['field' => 'pack_code', 'code' => 'pack_not_for_sku', 'message' => "{$sku->sku_code} has no pack {$request->packCode()}."]]);

            $outcome = $this->refusals(fn () => $this->goodsIn->receive($receipt, new ReceiveLine(
                clientToken: $request->clientToken(),
                purchaseOrderLineId: $this->purchaseOrderLineId($request->purchaseOrderLine()),
                skuId: $sku->id,
                packId: (int) $packId,
                packQty: $request->packQty(),
                batchCode: $request->batchCode(),
                expiresOn: $request->expiresOn(),
                serials: $request->serials(),
                binId: $this->binId($request->binCode(), $receipt),
                unitCostE4: $request->unitCostE4(),
                expiryWarningsConfirmed: $request->expiryConfirmed(),
                actorUserId: $this->actorId($request),
            )));

            return response()->json([
                'data' => [
                    'replayed' => $outcome->replayed,
                    'base_qty' => $outcome->line->base_qty,
                    'receipt' => (new GoodsReceiptResource($receipt->refresh()))->resolve($request),
                ],
            ], $outcome->replayed ? 200 : 201);
        });
    }

    public function close(CloseGoodsReceiptRequest $request, string $id): JsonResponse
    {
        $receipt = $this->receipt($id);
        Gate::authorize('update', $receipt);

        $decisions = array_map(function (array $v) {
            $lineId = $this->purchaseOrderLineId(['po_number' => $v['po_number'], 'line_no' => $v['line_no']]);

            return new VarianceDecision((int) $lineId, $v['reason']);
        }, $request->variances());

        $closed = $this->refusals(fn () => $this->goodsIn->close($receipt, $decisions, $this->actorId($request)));

        return (new GoodsReceiptResource($closed))->response();
    }

    /**
     * 05.5 §9 / §12: a scan resolves to what it identifies; an unknown
     * code answers with search candidates rather than failing.
     */
    public function lookup(GoodsInLookupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', GoodsReceipt::class);

        $receipt = $request->receiptPublicId() === null ? null : $this->receipt($request->receiptPublicId());
        $match = $this->scanner->resolve($request->code(), $receipt);
        $policy = new ExpiryPolicy;

        $data = match ($match['kind']) {
            'purchase_order' => ['kind' => 'purchase_order', 'reference' => $match['purchase_order']->po_number, 'status' => $match['purchase_order']->status],
            'container' => ['kind' => 'container', 'reference' => $match['container']->container_ref, 'status' => $match['container']->status],
            'sku' => ['kind' => 'sku', 'sku' => GoodsReceiptResource::sku($match['sku'], $policy), 'pack_code' => $match['pack']?->code],
            'bin' => ['kind' => 'bin', 'bin_code' => $match['bin']->code],
            'unknown' => ['kind' => 'unknown', 'code' => $request->code(), 'candidates' => array_map(fn (Sku $s) => GoodsReceiptResource::sku($s, $policy), $match['candidates'])],
        };

        if ($receipt !== null) {
            $data['horizon_days'] = $policy->horizonDays($receipt->location_id);
        }

        return response()->json(['data' => $data]);
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }

    private function receipt(string $publicId): GoodsReceipt
    {
        return GoodsReceipt::query()->where('public_id', $publicId)->first()
            ?? throw new ApiException(404, 'not_found', 'Not found.');
    }

    /**
     * @param  array{po_number: string, line_no: int}|null  $ref
     */
    private function purchaseOrderLineId(?array $ref): ?int
    {
        if ($ref === null) {
            return null;
        }

        $lineId = PurchaseOrderLine::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.po_number', $ref['po_number'])
            ->where('purchase_order_lines.line_no', $ref['line_no'])
            ->value('purchase_order_lines.id');

        if ($lineId === null) {
            $message = "{$ref['po_number']} has no line {$ref['line_no']}.";

            throw new ApiException(422, 'po_line_not_found', $message, [['field' => 'purchase_order_line', 'code' => 'po_line_not_found', 'message' => $message]]);
        }

        return (int) $lineId;
    }

    /** A bin code is only meaningful at the receipt's location. */
    private function binId(?string $code, GoodsReceipt $receipt): ?int
    {
        if ($code === null) {
            return null;
        }

        $binId = Bin::query()->where('location_id', $receipt->location_id)->where('code', $code)->value('id');
        if ($binId === null) {
            $message = "No bin {$code} at this location.";

            throw new ApiException(422, 'bin_not_found', $message, [['field' => 'bin_code', 'code' => 'bin_not_found', 'message' => $message]]);
        }

        return (int) $binId;
    }

    private function idOrReject(mixed $id, string $code, string $message): int
    {
        if ($id === null) {
            throw new ApiException(422, $code, $message, [['field' => 'reference', 'code' => $code, 'message' => $message]]);
        }

        return (int) $id;
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
        } catch (GoodsInRejectedException $e) {
            throw new ApiException($e->status, $e->errorCode, $e->getMessage(), $e->details());
        }
    }
}
