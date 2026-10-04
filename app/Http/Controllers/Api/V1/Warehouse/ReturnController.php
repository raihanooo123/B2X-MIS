<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\ProofOfSending;
use App\Domain\Returns\ReturnReceipt;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Warehouse\ReceiveReturnRequest;
use App\Http\Requests\Api\V1\Warehouse\RejectReturnProofRequest;
use App\Http\Resources\Api\V1\Warehouse\ReturnResource;
use App\Http\Support\Idempotency;
use App\Models\Rma;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 06 §8 `/warehouse/returns/*` — the staff side of a return (05.4 §7.3,
 * §13.5): find it by its RMA number, book the parcel in, and reject a
 * consumer's invalid proof of sending. RmaPolicy decides who. Booking in
 * requires an Idempotency-Key (06 §6), like every warehouse write.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnReceipt $receipts = new ReturnReceipt,
        private readonly ProofOfSending $proofs = new ProofOfSending,
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
