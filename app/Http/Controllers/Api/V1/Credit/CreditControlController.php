<?php

namespace App\Http\Controllers\Api\V1\Credit;

use App\Domain\Collection\SlotUnavailable;
use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\CreditControl;
use App\Domain\Credit\CreditOverview;
use App\Domain\Credit\CreditPayouts;
use App\Domain\Credit\TradeApprovals;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\NoEligibleBatchException;
use App\Http\Controllers\Api\V1\Trade\ApprovalController;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Credit\DecideApprovalRequest;
use App\Http\Requests\Api\V1\Credit\RejectPayoutRequest;
use App\Http\Requests\Api\V1\Credit\RequestPayoutRequest;
use App\Http\Requests\Api\V1\Credit\UpdateCompanyCreditRequest;
use App\Http\Support\Idempotency;
use App\Models\AccountCreditPayout;
use App\Models\Company;
use App\Models\OrderApprovalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 05.2 §18.3 — accounts/admin credit control over the API (the Filament
 * screens call the same services): limit/terms/suspension, credit
 * shortfall decisions, and balance payouts with second-person approval.
 * Every action re-authorises in its service under the company lock.
 */
class CreditControlController extends Controller
{
    public function update(UpdateCompanyCreditRequest $request, string $company, CreditControl $control): JsonResponse
    {
        $record = Company::query()->where('public_id', $company)->firstOrFail();
        $updated = $control->update($record->id, $this->actor($request), $request->limitMinor(),
            (string) $request->validated('payment_terms'), (string) $request->validated('status'),
            (string) $request->validated('reason'), $request->validated('suspension_reason'));

        return response()->json(['data' => (new CreditOverview)->summary($updated->refresh())]);
    }

    public function approveException(DecideApprovalRequest $request, string $id): JsonResponse
    {
        return Idempotency::run($request, 'credit-exception-decision', fn () => $this->decideException($request, $id, true));
    }

    public function rejectException(DecideApprovalRequest $request, string $id): JsonResponse
    {
        return Idempotency::run($request, 'credit-exception-decision', fn () => $this->decideException($request, $id, false));
    }

    public function requestPayout(RequestPayoutRequest $request, string $company, CreditPayouts $payouts): JsonResponse
    {
        return Idempotency::run($request, 'credit-payout-request', function () use ($request, $company, $payouts): JsonResponse {
            $record = Company::query()->where('public_id', $company)->firstOrFail();
            $payout = $payouts->request($record->id, $this->actor($request), (string) $request->validated('method'),
                $request->amountMinor(), $request->validated('source_payment_id'), (string) $request->validated('reason'));

            return response()->json(['data' => self::payout($payout)], 201);
        });
    }

    public function approvePayout(Request $request, string $id, CreditPayouts $payouts): JsonResponse
    {
        return Idempotency::run($request, 'credit-payout-approve', function () use ($request, $id, $payouts): JsonResponse {
            $payout = AccountCreditPayout::query()->where('public_id', $id)->firstOrFail();
            $payouts->approve($payout->id, $this->actor($request));
            // After commit (CLAUDE.md invariant 6): the card refund, then the outcome.
            $payouts->settle($payout->id);

            return response()->json(['data' => self::payout($payout->refresh())]);
        });
    }

    public function rejectPayout(RejectPayoutRequest $request, string $id, CreditPayouts $payouts): JsonResponse
    {
        $payout = AccountCreditPayout::query()->where('public_id', $id)->firstOrFail();
        $payouts->reject($payout->id, $this->actor($request), (string) $request->validated('reason'));

        return response()->json(['data' => self::payout($payout->refresh())]);
    }

    private function decideException(DecideApprovalRequest $request, string $id, bool $approve): JsonResponse
    {
        $record = OrderApprovalRequest::query()->where('public_id', $id)
            ->where('approval_kind', ApprovalKind::CreditException->value)->firstOrFail();
        Gate::forUser($this->actor($request))->authorize('view', $record);

        try {
            $decided = (new TradeApprovals)->decide($record->id, $this->actor($request), $approve, $request->reason());
        } catch (InsufficientStockException|NoEligibleBatchException) {
            throw new ApiException(409, 'insufficient_stock', 'There is no longer enough stock for this order. Reject it so the buyer can order again.');
        } catch (SlotUnavailable $e) {
            throw new ApiException(409, 'slot_unavailable', $e->getMessage());
        }

        return response()->json(['data' => ApprovalController::result($decided)]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string, mixed> */
    private static function payout(AccountCreditPayout $payout): array
    {
        return [
            'id' => $payout->public_id,
            'method' => $payout->method,
            'amount_minor' => $payout->amount_minor,
            'status' => $payout->status,
            'requested_at' => $payout->requested_at->toIso8601ZuluString(),
            'completed_at' => $payout->completed_at?->toIso8601ZuluString(),
        ];
    }
}
