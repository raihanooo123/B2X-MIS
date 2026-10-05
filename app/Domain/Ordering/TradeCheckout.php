<?php

namespace App\Domain\Ordering;

use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\CreditGate;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\TradeApprovals;
use App\Domain\Inventory\AllocationService;
use App\Domain\Notifications\Notifications;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Order;

/**
 * Trade (company) checkout, 05.2 §8 and §18.1.
 *
 * Under the `companies` lock the live gates run again (CreditGate): an
 * active buying member, a company allowed to trade, and for on account
 * credit terms with no overdue debt. Then:
 *
 *   - gross above the buyer's limit, or a buyer who always needs
 *     approval → `awaiting_approval` with a buyer_limit request. Funded:
 *     stock, collection place and on-account credit are held. A card
 *     order is not authorised now; the buyer pays after approval
 *     (ApprovedOrderPayment), so no 48-hour card hold exists.
 *   - on account above available credit → `awaiting_approval` with a
 *     credit_exception request for accounts. Unfunded: no stock, slot or
 *     credit is reserved until it is approved (TradeApprovals::fund) or
 *     the buyer pays in advance.
 *   - otherwise exactly as before: stock, then the credit hold for on
 *     account (02 §11.1 step 6). A company paying by card takes no hold
 *     (05.2 §8.1 row 2).
 */
final class TradeCheckout implements CheckoutStrategy
{
    public function __construct(private readonly CreditGate $gate = new CreditGate) {}

    public function validate(CheckoutRequest $request): void
    {
        // Unlocked first look, so a refused buyer opens no transaction; reserve() decides.
        $this->gate->assertCanOrder(Company::query()->findOrFail($request->companyId), $request->userId, $request->paymentMethod);
    }

    public function tierId(CheckoutRequest $request): ?int
    {
        $tierId = Company::query()->where('id', $request->companyId)->value('price_tier_id');

        return $tierId === null ? null : (int) $tierId;
    }

    public function paymentStatus(CheckoutRequest $request): string
    {
        return $request->paymentMethod === PaymentMethod::OnAccount->value ? 'on_account' : 'unpaid';
    }

    public function reserve(
        AllocationService $allocationService,
        CheckoutRequest $request,
        Order $order,
        int $totalGrossMinor,
        array $allocationLines,
        ?callable $beforeStock = null,
    ): void {
        // 02 §11.1 step 1: companies first, always — every trade path takes it.
        $company = Company::query()->where('id', $request->companyId)->lockForUpdate()->firstOrFail();
        $member = $this->gate->assertCanOrder($company, $request->userId, $request->paymentMethod);

        $onAccount = $request->paymentMethod === PaymentMethod::OnAccount->value;
        $buyerApproval = $this->gate->needsBuyerApproval($member, $totalGrossMinor);
        $creditShortfall = $onAccount && $totalGrossMinor > $this->gate->available($company);

        if ($request->paymentMethod === PaymentMethod::Card->value) {
            if ($buyerApproval && $request->cardAuthorisation !== null) {
                throw new CreditRefused('approval_required', 'This order needs approval before payment. Place it without paying; you pay once it is approved.', 409);
            }
            if (! $buyerApproval && $request->cardAuthorisation === null) {
                throw new CreditRefused('card_authorisation_required', 'Authorise the card payment to place this order.', 409);
            }
        }

        if ($buyerApproval || $creditShortfall) {
            $order->forceFill(['status' => 'awaiting_approval'])->save();
            $approvals = new TradeApprovals;
            if ($buyerApproval) {
                $approvals->request($order, ApprovalKind::BuyerLimit);
            }
            if ($creditShortfall) {
                $approvals->request($order, ApprovalKind::CreditException);

                return; // §18.1: nothing reserved for an unfunded request.
            }
        }

        if ($beforeStock !== null) {
            $beforeStock();
        }

        if ($allocationLines !== []) {
            $allocationService->allocateWithinTransaction(null, 0, $allocationLines);
        }

        if ($onAccount) {
            $usageBefore = $company->credit_used_minor + $company->credit_held_minor;
            CreditHold::query()->create([
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount_minor' => $totalGrossMinor,
                'status' => 'held',
                'held_at' => now(),
            ]);
            Company::query()->where('id', $company->id)->increment('credit_held_minor', $totalGrossMinor);

            // 05.12 §5.1.2: warn the owners if this hold took usage past 80%.
            (new Notifications)->creditUsageChanged($company->id, $company->credit_limit_minor, $usageBefore, $usageBefore + $totalGrossMinor, "order:{$order->id}");
        }
    }
}
