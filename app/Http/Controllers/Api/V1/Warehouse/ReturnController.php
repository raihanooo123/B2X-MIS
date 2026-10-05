<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Domain\Returns\BankRefunds;
use App\Domain\Returns\ConsumerCancellations;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\FaultReports;
use App\Domain\Returns\ProofOfSending;
use App\Domain\Returns\ReplacementOrders;
use App\Domain\Returns\ReturnInspection;
use App\Domain\Returns\ReturnReceipt;
use App\Domain\Returns\ReturnResolution;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Warehouse\AdvanceReplacementRequest;
use App\Http\Requests\Api\V1\Warehouse\BankRefundRequest;
use App\Http\Requests\Api\V1\Warehouse\InspectReturnRequest;
use App\Http\Requests\Api\V1\Warehouse\ReasonRequest;
use App\Http\Requests\Api\V1\Warehouse\ReceiveReturnRequest;
use App\Http\Requests\Api\V1\Warehouse\RecordCancellationRequest;
use App\Http\Requests\Api\V1\Warehouse\RejectReturnProofRequest;
use App\Http\Requests\Api\V1\Warehouse\ResolveReturnRequest;
use App\Http\Resources\Api\V1\Warehouse\ReturnResource;
use App\Http\Support\Idempotency;
use App\Models\Order;
use App\Models\Rma;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 06 §8 `/warehouse/returns/*` — the staff side of a return (05.4 §7.3–7.5,
 * §13): find it by its RMA number, book the parcel in, inspect it, settle
 * it, review proof of sending and problem reports, record a bank refund,
 * and record a cancellation made by email or phone. RmaPolicy decides who.
 * Booking in, inspecting and settling require an Idempotency-Key (06 §6):
 * they move stock or money.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnReceipt $receipts = new ReturnReceipt,
        private readonly ProofOfSending $proofs = new ProofOfSending,
        private readonly ReturnInspection $inspections = new ReturnInspection,
        private readonly ReturnResolution $resolutions = new ReturnResolution,
        private readonly FaultReports $faults = new FaultReports,
        private readonly BankRefunds $bankRefunds = new BankRefunds,
        private readonly ConsumerCancellations $cancellations = new ConsumerCancellations,
        private readonly ReplacementOrders $replacements = new ReplacementOrders,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Rma::class);
        $number = strtoupper(trim((string) $request->query('rma_number', '')));

        $rma = $number === '' ? null : Rma::query()->where('rma_number', $number)->first();
        if ($rma === null) {
            throw new ApiException(404, 'not_found', "No return with number {$number}.");
        }

        return (new ReturnResource($rma))->response();
    }

    public function receive(ReceiveReturnRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('receive', $rma);

        return Idempotency::run($request, 'returns-receive:'.$rma->public_id, function () use ($request, $rma) {
            $received = $this->refusals(fn () => $this->receipts->receive($rma->id, $request->receivedByLineNo(), $this->staffId($request)));

            return (new ReturnResource($received))->response();
        });
    }

    public function rejectProof(RejectReturnProofRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('rejectProof', $rma);

        $rejected = $this->refusals(fn () => $this->proofs->reject($rma->id, $this->staffId($request), $request->reason()));

        return (new ReturnResource($rejected))->response();
    }

    public function inspect(InspectReturnRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('inspect', $rma);

        return Idempotency::run($request, 'returns-inspect:'.$rma->public_id, fn () => (new ReturnResource(
            $this->refusals(fn () => $this->inspections->inspect($rma->id, $request->decisions(), $this->staffId($request)))
        ))->response());
    }

    public function resolve(ResolveReturnRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('resolve', $rma);

        return Idempotency::run($request, 'returns-resolve:'.$rma->public_id, fn () => (new ReturnResource(
            $this->refusals(fn () => $this->resolutions->resolve($rma->id, $this->staffId($request), $request->resolutionType(), $request->validated('override_basis'), $request->validated('remedy_outcome'), $request->validated('remedy_reason'), $request->replacementAddress()))
        ))->response());
    }

    /** 05.4 §14.2 R7 (Q-R2): a replacement sent before the faulty goods come back — staff only, with a reason. */
    public function advanceReplacement(AdvanceReplacementRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('resolve', $rma);

        return Idempotency::run($request, 'returns-advance-replacement:'.$rma->public_id, function () use ($request, $rma) {
            $this->refusals(fn () => $this->replacements->createAdvance($rma->id, $this->staffId($request), $request->reason(), $request->replacementAddress()));

            return (new ReturnResource($rma->fresh() ?? $rma))->response();
        });
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('review', $rma);

        return (new ReturnResource($this->refusals(fn () => $this->faults->approve($rma->id, $this->staffId($request)))))->response();
    }

    public function reject(ReasonRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('review', $rma);

        return (new ReturnResource($this->refusals(fn () => $this->faults->reject($rma->id, $this->staffId($request), $request->reason()))))->response();
    }

    public function bankRefund(BankRefundRequest $request, string $id): JsonResponse
    {
        $rma = $this->rma($id);
        Gate::authorize('recordRefund', $rma);

        return (new ReturnResource($this->refusals(fn () => $this->bankRefunds->record($rma->id, $request->reference(), $this->staffId($request)))))->response();
    }

    /** 05.4 §13.3 (S6e): a cancellation the customer made by email or phone. */
    public function recordCancellation(RecordCancellationRequest $request): JsonResponse
    {
        Gate::authorize('recordCancellation', Rma::class);
        $order = Order::query()->where('order_number', $request->orderNumber())->first()
            ?? throw new ApiException(404, 'not_found', "No order with number {$request->orderNumber()}.");

        try {
            $rma = $this->cancellations->request($order->id, $request->packQtyByLineNo(), $request->notifiedAt(), null, $this->staffId($request));
        } catch (CancellationRequestRejectedException $e) {
            throw new ApiException(422, $e->reason, $e->getMessage(), [['field' => $e->field, 'code' => $e->reason, 'message' => $e->getMessage()]]);
        }

        return (new ReturnResource($rma))->response()->setStatusCode(201);
    }

    private function rma(string $publicId): Rma
    {
        return Rma::query()->where('public_id', $publicId)->first()
            ?? throw new ApiException(404, 'not_found', 'Not found.');
    }

    private function staffId(Request $request): int
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : throw new ApiException(401, 'unauthenticated', 'Sign in.');
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
        } catch (ReturnActionRefusedException $e) {
            throw new ApiException(422, $e->reason, $e->getMessage());
        }
    }
}
