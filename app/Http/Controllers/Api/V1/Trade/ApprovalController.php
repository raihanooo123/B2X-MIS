<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\TradeApprovals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credit\BulkRejectApprovalsRequest;
use App\Http\Requests\Api\V1\Credit\DecideApprovalRequest;
use App\Http\Support\Idempotency;
use App\Http\Support\TradeContext;
use App\Models\OrderApprovalRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §18.3 — a company approver decides buyer-limit requests in the
 * acting company: POST /api/v1/approvals/{id}/approve|reject, and the
 * bulk rejection of selected loaded rows. Idempotency-Key required (06
 * §18). TradeApprovals re-checks everything under the company lock.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly TradeApprovals $approvals = new TradeApprovals) {}

    public function approve(DecideApprovalRequest $request, string $id): JsonResponse
    {
        return Idempotency::run($request, 'approval-decision', fn () => $this->decide($request, $id, true));
    }

    public function reject(DecideApprovalRequest $request, string $id): JsonResponse
    {
        return Idempotency::run($request, 'approval-decision', fn () => $this->decide($request, $id, false));
    }

    /**
     * 05.16 §3: each selected request is validated on its own; failures are
     * reported per record, never silently skipped.
     */
    public function bulkReject(BulkRejectApprovalsRequest $request): JsonResponse
    {
        return Idempotency::run($request, 'approval-bulk-reject', function () use ($request): JsonResponse {
            [$user, $company] = TradeContext::resolve($request);
            $records = OrderApprovalRequest::query()->whereIn('public_id', $request->ids())->where('company_id', $company->id)
                ->get(['id', 'public_id'])->keyBy('public_id');

            $results = [];
            foreach ($request->ids() as $publicId) {
                $record = $records->get($publicId);
                if ($record === null) {
                    $results[] = ['id' => $publicId, 'ok' => false, 'code' => 'not_found', 'message' => 'Not found.'];

                    continue;
                }
                try {
                    $decided = $this->approvals->decide($record->id, $user, false, $request->reason());
                    $results[] = ['id' => $publicId, 'ok' => true, 'code' => null, 'message' => null, 'status' => $decided->status];
                } catch (CreditRefused $e) {
                    $results[] = ['id' => $publicId, 'ok' => false, 'code' => $e->reason, 'message' => $e->getMessage()];
                } catch (AuthorizationException) {
                    $results[] = ['id' => $publicId, 'ok' => false, 'code' => 'forbidden', 'message' => 'You cannot decide this request.'];
                } catch (ValidationException $e) {
                    $results[] = ['id' => $publicId, 'ok' => false, 'code' => 'validation_failed', 'message' => (string) collect($e->errors())->flatten()->first()];
                }
            }

            return response()->json(['data' => $results]);
        });
    }

    private function decide(DecideApprovalRequest $request, string $id, bool $approve): JsonResponse
    {
        [$user, $company] = TradeContext::resolve($request);
        $record = OrderApprovalRequest::query()->where('public_id', $id)->where('company_id', $company->id)
            ->where('approval_kind', ApprovalKind::BuyerLimit->value)->firstOrFail();

        $decided = $this->approvals->decide($record->id, $user, $approve, $request->reason());

        return response()->json(['data' => self::result($decided)]);
    }

    /** @return array<string, mixed> */
    public static function result(OrderApprovalRequest $decided): array
    {
        $decided->loadMissing('order:id,public_id,status');

        return [
            'id' => $decided->public_id,
            'status' => $decided->status,
            'decided_at' => $decided->decided_at?->toIso8601ZuluString(),
            'order' => ['id' => $decided->order->public_id ?? null, 'status' => $decided->order->status ?? null],
        ];
    }
}
