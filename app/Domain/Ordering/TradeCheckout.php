<?php
namespace App\Domain\Ordering;
use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\CreditGate;
use App\Domain\Credit\CreditLedger;
use App\Domain\Credit\CreditRefused;
use App\Domain\Credit\TradeApprovals;
use App\Domain\Inventory\AllocationService;
use App\Domain\Notifications\Notifications;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Order;

/** 05.2 §18: all trade checkout methods share live membership/company gates. */
final class TradeCheckout implements CheckoutStrategy
{
    public function validate(CheckoutRequest $request): void
    {
        $company = Company::query()->findOrFail($request->companyId);
        (new CreditGate)->assertCanOrder($company, $request->userId, $request->paymentMethod);
    }
    public function tierId(CheckoutRequest $request): ?int
    {
        $id = Company::query()->where('id', $request->companyId)->value('price_tier_id');
        return $id === null ? null : (int) $id;
    }
    public function paymentStatus(CheckoutRequest $request): string
    { return $request->paymentMethod === 'on_account' ? 'on_account' : 'unpaid'; }
    public function reserve(AllocationService $allocationService, CheckoutRequest $request, Order $order, int $totalGrossMinor, array $allocationLines, ?callable $beforeStock = null): void
    {
        $company = Company::query()->where('id', $request->companyId)->lockForUpdate()->firstOrFail();
        $gate = new CreditGate;
        $member = $gate->assertCanOrder($company, $request->userId, $request->paymentMethod);
        $buyerApproval = $gate->needsBuyerApproval($member, $totalGrossMinor);
        $balance = $request->applyAccountCredit ? min($company->account_balance_minor, $totalGrossMinor) : 0;
        $exposure = $totalGrossMinor - $balance;
        if ($buyerApproval && $request->cardAuthorisation !== null) {
            throw new CreditRefused('approval_before_card', 'This order needs buyer approval before card authorisation.', 409);
        }
        $fundingApproval = $request->paymentMethod === 'on_account' && $exposure > 0 && $exposure > $gate->available($company);
        if ($buyerApproval || $fundingApproval) {
            $order->forceFill(['status' => 'awaiting_approval'])->save();
            if ($buyerApproval) { (new TradeApprovals)->request($order, ApprovalKind::BuyerLimit); }
            if ($fundingApproval) { (new TradeApprovals)->request($order, ApprovalKind::CreditException); }
        }
        if ($fundingApproval) { return; } // no balance/stock/slot/credit consumed by an unfunded request
        if ($request->cardAuthorisation !== null && $request->cardAuthorisation->amountMinor !== $exposure) {
            throw new CreditRefused('balance_changed', 'The amount to pay changed. Review the balance and card payment again.', 409);
        }
        if ($beforeStock !== null) { $beforeStock(); }
        if ($allocationLines !== []) { $allocationService->allocateWithinTransaction(null, 0, $allocationLines); }
        (new CreditLedger)->applyToOrder($company, $order, $balance, $request->placedByUserId ?? $request->userId);
        if ($request->paymentMethod === 'on_account' && $exposure > 0) {
            CreditHold::query()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => $exposure, 'status' => 'held', 'held_at' => now()]);
            Company::query()->where('id', $company->id)->increment('credit_held_minor', $exposure);
            (new Notifications)->creditUsageChanged($company->id, $company->credit_limit_minor,
                $company->credit_used_minor + $company->credit_held_minor,
                $company->credit_used_minor + $company->credit_held_minor + $exposure, 'order:'.$order->id);
        }
        if (! $buyerApproval) {
            if ($exposure === 0) { $order->forceFill(['payment_status' => 'paid'])->save(); }
            elseif ($request->paymentMethod === 'card' && $request->cardAuthorisation === null) { $order->forceFill(['status' => 'pending_payment'])->save(); }
        }
    }
}
