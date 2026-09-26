<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Domain\Warehouse\StocktakeService;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Warehouse\CountStocktakeLineRequest;
use App\Http\Requests\Api\V1\Warehouse\PostStocktakeRequest;
use App\Http\Requests\Api\V1\Warehouse\StartStocktakeRequest;
use App\Http\Requests\Api\V1\Warehouse\StocktakeIdentityRequest;
use App\Http\Requests\Api\V1\Warehouse\StocktakeSerialRequest;
use App\Http\Resources\Api\V1\Warehouse\StocktakeResource;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Pack;
use App\Models\Sku;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Doc 06 §8 — `/warehouse/stocktakes`, `/stocktakes/{id}/lines`, `/post`
 * (C R A), plus serial scans and the review / reopen / cancel actions
 * (05.5 §8, 02 §24).
 *
 * Thin: identifiers become rows — location code, SKU public id, batch
 * code, pack code — and StocktakeService does the rest. Its refusals
 * become 06 §4's envelope. Every action answers with the whole
 * stocktake. Counting is idempotent by nature (a count is set, not added;
 * a serial scan is unique per line), and posting is idempotent on the
 * stocktake, so no Idempotency-Key is required.
 */
class StocktakeController extends Controller
{
    public function __construct(
        private readonly StocktakeService $stocktakes = new StocktakeService,
    ) {}

    public function store(StartStocktakeRequest $request): JsonResponse
    {
        Gate::authorize('create', Stocktake::class);

        $locationId = Location::query()->where('code', $request->locationCode())->value('id')
            ?? throw $this->unprocessable('location_not_found', 'No such location.', 'location_code');

        $stocktake = $this->stocktakes->start((int) $locationId, $request->blind(), $this->actorId($request));

        return (new StocktakeResource($stocktake))->response()->setStatusCode(201)
            ->header('Location', "/api/v1/warehouse/stocktakes/{$stocktake->public_id}");
    }

    public function show(string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('view', $stocktake);

        return (new StocktakeResource($stocktake))->response();
    }

    public function count(CountStocktakeLineRequest $request, string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        [$sku, $batch] = $this->identity($request);
        $pack = Pack::query()->where('sku_id', $sku->id)->where('code', $request->packCode())->first()
            ?? throw $this->unprocessable('pack_not_for_sku', "{$sku->sku_code} has no pack {$request->packCode()}.", 'pack_code');
        $counted = $request->packQty() * $pack->base_units + $request->looseUnits();

        $this->refusals(fn () => $this->stocktakes->count($stocktake, $sku, $batch, $counted, $this->actorId($request)));

        return $this->fresh($stocktake);
    }

    public function scanSerial(StocktakeSerialRequest $request, string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        [$sku, $batch] = $this->identity($request);
        $this->refusals(fn () => $this->stocktakes->scanSerial($stocktake, $sku, $batch, $request->serialNumber(), $this->actorId($request)));

        return $this->fresh($stocktake);
    }

    public function removeSerial(StocktakeSerialRequest $request, string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        [$sku, $batch] = $this->identity($request);
        $this->refusals(fn () => $this->stocktakes->removeSerial($stocktake, $sku, $batch, $request->serialNumber(), $this->actorId($request)));

        return $this->fresh($stocktake);
    }

    public function review(string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        $this->refusals(fn () => $this->stocktakes->submitForReview($stocktake));

        return $this->fresh($stocktake);
    }

    public function reopen(string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        $this->refusals(fn () => $this->stocktakes->reopen($stocktake));

        return $this->fresh($stocktake);
    }

    public function post(PostStocktakeRequest $request, string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        $reasons = [];
        foreach ($request->reasons() as $i => $entry) {
            $skuId = Sku::query()->where('public_id', $entry['sku_id'])->value('id');
            $batchId = $entry['batch_code'] === null || $skuId === null ? null : Batch::query()->where('sku_id', $skuId)->where('batch_code', $entry['batch_code'])->value('id');
            $lineId = $skuId === null ? null : StocktakeLine::query()
                ->where('stocktake_id', $stocktake->id)
                ->where('sku_id', $skuId)
                ->when($batchId === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $batchId))
                ->value('id');
            if ($lineId === null) {
                throw $this->unprocessable('line_not_counted', 'A reason was given for something not counted on this stocktake.', "reasons.{$i}");
            }
            $reasons[(int) $lineId] = $entry['reason'];
        }

        $this->refusals(fn () => $this->stocktakes->post($stocktake, $reasons, $this->actorId($request)));

        return $this->fresh($stocktake);
    }

    public function cancel(string $id): JsonResponse
    {
        $stocktake = $this->stocktake($id);
        Gate::authorize('update', $stocktake);

        $this->refusals(fn () => $this->stocktakes->cancel($stocktake));

        return $this->fresh($stocktake);
    }

    /**
     * @return array{0: Sku, 1: ?Batch}
     */
    private function identity(StocktakeIdentityRequest $request): array
    {
        $sku = Sku::query()->where('public_id', $request->skuPublicId())->first()
            ?? throw $this->unprocessable('sku_not_found', 'No SKU with that id.', 'sku_id');

        if ($request->batchCode() === null) {
            return [$sku, null];
        }

        $batch = Batch::query()->where('sku_id', $sku->id)->where('batch_code', $request->batchCode())->first()
            ?? throw $this->unprocessable('batch_not_found', "No batch {$request->batchCode()} of {$sku->sku_code}. Stock in an unrecorded batch is received through goods-in first.", 'batch_code');

        return [$sku, $batch];
    }

    private function fresh(Stocktake $stocktake): JsonResponse
    {
        return (new StocktakeResource($stocktake->refresh()))->response();
    }

    private function stocktake(string $publicId): Stocktake
    {
        return Stocktake::query()->where('public_id', $publicId)->first()
            ?? throw new ApiException(404, 'not_found', 'Not found.');
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
