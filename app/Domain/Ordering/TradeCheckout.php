<?php

namespace App\Domain\Ordering;

use App\Domain\Inventory\AllocationService;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Order;

/**
 * Trade (company) checkout. A company customer isn't automatically
 * on-account — 05.2 §8.1 row 2: paying by card takes no credit hold at
 * all, exactly like ConsumerCheckout. The credit gate and hold apply
 * only when `paymentMethod === 'on_account'`; `creditCompanyId()`
 * captures that sub-branch so `reserve()` reads as one sequence rather
 * than a nested condition.
 *
 * Deliberately NOT built here (unchanged from before this split; see
 * the session report): the awaiting_approval fallback for an on-account
 * order that exceeds credit (05.2 §8.1 row 3 — this still throws
 * InsufficientCreditException and commits nothing), suspended-company
 * and overdue-invoice blocking, and the §10 multi-user approval flow.
 * `validate()` is the seam those belong in once built.
 */
final class TradeCheckout implements CheckoutStrategy
{
    public function validate(CheckoutRequest $request): void
    {
        // Nothing to reject yet — a company may check out on_account or
        // by card. Suspended-company / overdue-invoice blocking (05.2
        // §8.1 rows 4-5) belongs here once built.
    }

    public function tierId(CheckoutRequest $request): ?int
    {
        $tierId = Company::query()->whereKey($request->companyId)->value('price_tier_id');

        return $tierId === null ? null : (int) $tierId;
    }

    public function paymentStatus(CheckoutRequest $request): string
    {
        return $this->creditCompanyId($request) !== null ? 'on_account' : 'unpaid';
    }

    public function reserve(
        AllocationService $allocationService,
        CheckoutRequest $request,
        Order $order,
        int $totalGrossMinor,
        array $allocationLines,
    ): void {
        $creditCompanyId = $this->creditCompanyId($request);

        // 02 §11.1: companies locked before stock_levels. allocateWithinTransaction()
        // does both, in that order, when a credit gate applies; with no
        // stock lines at all (every SKU untracked) the credit gate still
        // has to run on its own — see AllocationService::lockAndCheckCredit()'s
        // own docblock for why.
        if ($allocationLines !== []) {
            $allocationService->allocateWithinTransaction($creditCompanyId, $totalGrossMinor, $allocationLines);
        } elseif ($creditCompanyId !== null) {
            $allocationService->lockAndCheckCredit($creditCompanyId, $totalGrossMinor);
        }

        // 02 §11.1 step 6: credit_holds is inserted AFTER stock is
        // verified/written, not alongside the step-1 credit lock. The
        // company row is still held from the lock above, so this needs
        // no further lock.
        if ($creditCompanyId !== null) {
            CreditHold::create([
                'company_id' => $creditCompanyId,
                'order_id' => $order->id,
                'amount_minor' => $totalGrossMinor,
                'status' => 'held',
                'held_at' => now(),
            ]);
            Company::whereKey($creditCompanyId)->increment('credit_held_minor', $totalGrossMinor);
        }
    }

    /**
     * On account only when the buyer chose it — a company paying by
     * card takes no credit hold (05.2 §8.1 row 2).
     */
    private function creditCompanyId(CheckoutRequest $request): ?int
    {
        return $request->paymentMethod === 'on_account' ? $request->companyId : null;
    }
}
