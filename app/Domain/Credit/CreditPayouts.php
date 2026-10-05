<?php

namespace App\Domain\Credit;

use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\Refunds;
use App\Filament\Support\MoneyFormatter;
use App\Models\AccountCreditPayout;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 02 §31.1, 05.2 §18.1 — paying spendable account balance back out.
 *
 *   request  accounts/admin; the amount is reserved at once with a
 *            `payout_reserved` movement, before any external call.
 *   approve  a second accounts/admin person; the refund row is written
 *            `pending` in the same transaction; the gateway is called
 *            after commit (settle). Success → paid, no second debit.
 *            Definite failure → failed and one `reversal` gives it back.
 *   reject   a pending request is released with one `reversal`.
 *
 * Only to the original card at launch: the source payment must be this
 * company's captured card payment, and the payout is capped at what is
 * still refundable on it (refunds and other live payouts subtracted).
 * Bank payouts wait on a verified bank-details spec (05.2 §18.5).
 */
final class CreditPayouts
{
    public function __construct(
        private readonly CreditLedger $ledger = new CreditLedger,
        private readonly CreditAudit $audit = new CreditAudit,
    ) {}

    /** @throws CreditRefused */
    public function request(int $companyId, User $actor, string $method, int $amountMinor, ?string $sourcePaymentPublicId, string $reason): AccountCreditPayout
    {
        $reason = trim($reason);
        if ($method === 'bank') {
            throw new CreditRefused('bank_payout_unavailable', 'Bank payouts need verified bank details, which are not available yet. Refund to the original card instead.');
        }
        if ($method !== 'original_card' || $amountMinor <= 0 || $reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['amount' => 'Choose the original card, a positive amount and a reason.']);
        }

        return DB::transaction(function () use ($companyId, $actor, $amountMinor, $sourcePaymentPublicId, $reason): AccountCreditPayout {
            $company = Company::query()->where('id', $companyId)->lockForUpdate()->firstOrFail();
            Gate::forUser(User::query()->findOrFail($actor->id))->authorize('manageCredit', $company);

            $source = Payment::query()->where('public_id', (string) $sourcePaymentPublicId)->where('company_id', $company->id)
                ->where('type', 'payment')->where('gateway', 'stripe')->whereIn('status', ['captured', 'part_refunded'])
                ->lockForUpdate()->first();
            if ($source === null) {
                throw new CreditRefused('source_payment_invalid', 'Choose a captured card payment made by this company.');
            }
            $refundable = $this->refundableMinor($source);
            if ($amountMinor > $refundable) {
                throw new CreditRefused('payout_exceeds_card', 'Only '.MoneyFormatter::minor($refundable).' can still go back to that card.');
            }
            if ($amountMinor > $company->account_balance_minor) {
                throw new CreditRefused('balance_unavailable', 'That is more than the spendable account balance.', 409);
            }

            $payout = new AccountCreditPayout;
            $payout->forceFill([
                'public_id' => (string) Str::ulid(),
                'company_id' => $company->id,
                'method' => 'original_card',
                'amount_minor' => $amountMinor,
                'source_payment_id' => $source->id,
                'destination_reference' => 'payment:'.$source->public_id,
                'status' => CreditPayoutStatus::Pending->value,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now(),
            ]);
            $payout->event_key = 'payout:'.$payout->public_id;
            $payout->save();

            $this->ledger->append($company, $payout->event_key, [[
                'type' => CreditMovementType::PayoutReserved, 'amount' => -$amountMinor, 'payment_id' => $source->id, 'reason' => 'payout_requested', 'note' => $reason,
            ]], $actor->id);
            $this->audit->record($company->id, 'account_credit_payout', $payout->id, ['status' => 'none'], ['status' => 'pending', 'amount_minor' => $amountMinor], $actor->id, $reason);

            return $payout;
        });
    }

    /**
     * Second-person approval. Returns the pending refund row id to settle
     * after commit.
     *
     * @throws CreditRefused
     */
    public function approve(int $payoutId, User $approver): int
    {
        return DB::transaction(function () use ($payoutId, $approver): int {
            $peek = AccountCreditPayout::query()->findOrFail($payoutId, ['id', 'company_id']);
            $company = Company::query()->where('id', $peek->company_id)->lockForUpdate()->firstOrFail();
            Gate::forUser(User::query()->findOrFail($approver->id))->authorize('manageCredit', $company);
            $payout = AccountCreditPayout::query()->where('id', $payoutId)->lockForUpdate()->firstOrFail();
            if ($payout->requested_by_user_id === $approver->id) {
                throw new CreditRefused('second_approver_required', 'Another accounts or admin person must approve a payout they did not request.', 403);
            }
            if ($payout->status !== CreditPayoutStatus::Pending->value) {
                throw new CreditRefused('payout_not_pending', 'This payout is already '.$payout->status.'.', 409);
            }

            $source = Payment::query()->where('id', $payout->source_payment_id)->lockForUpdate()->firstOrFail();
            $refund = Refunds::recordPending($source, $payout->amount_minor);
            $payout->forceFill([
                'status' => CreditPayoutStatus::Processing->value,
                'approved_by_user_id' => $approver->id,
                'completed_payment_id' => $refund->id,
            ])->save();
            $this->audit->record($company->id, 'account_credit_payout', $payout->id, ['status' => 'pending'], ['status' => 'processing'], $approver->id, 'payout_approved');

            return $refund->id;
        });
    }

    /**
     * After commit: the card refund, then the payout's own outcome. Not
     * RefundSettlement: its failure path asks accounts to repay by bank
     * transfer, but a failed payout goes back to the balance instead —
     * doing both would pay the customer twice.
     */
    public function settle(int $payoutId): void
    {
        $payout = AccountCreditPayout::query()->findOrFail($payoutId);
        $refund = $payout->completed_payment_id === null ? null : Payment::query()->find($payout->completed_payment_id);
        $source = $payout->source_payment_id === null ? null : Payment::query()->find($payout->source_payment_id);
        if ($payout->status !== CreditPayoutStatus::Processing->value || $refund === null || $refund->status !== 'pending' || $source?->gateway_reference === null) {
            return;
        }

        try {
            $reference = app(PaymentGateway::class)->refund($source->gateway_reference, $refund->amount_minor, 'payout:'.$payout->public_id);
            Refunds::markSucceeded($refund->id, $reference);
        } catch (PaymentGatewayException $e) {
            Refunds::markFailed($refund->id, $e->getMessage());
            Log::warning('Balance payout refused by the card gateway; returned to the account balance.', ['payout' => $payout->public_id, 'error' => $e->getMessage()]);
        }

        DB::transaction(function () use ($payoutId): void {
            $peek = AccountCreditPayout::query()->findOrFail($payoutId, ['id', 'company_id']);
            $company = Company::query()->where('id', $peek->company_id)->lockForUpdate()->firstOrFail();
            $payout = AccountCreditPayout::query()->where('id', $payoutId)->lockForUpdate()->firstOrFail();
            $refundStatus = Payment::query()->where('id', $payout->completed_payment_id)->value('status');
            if ($payout->status !== CreditPayoutStatus::Processing->value) {
                return;
            }
            if ($refundStatus === 'captured') {
                $payout->forceFill(['status' => CreditPayoutStatus::Paid->value, 'completed_at' => now()])->save();
                $this->audit->record($company->id, 'account_credit_payout', $payout->id, ['status' => 'processing'], ['status' => 'paid'], null, 'card_refund_succeeded');
            } elseif ($refundStatus === 'failed') {
                $this->fail($company, $payout, 'card_refund_failed', null);
            }
            // Anything else is an unknown outcome: it stays reserved for reconciliation.
        });
    }

    /** @throws CreditRefused */
    public function reject(int $payoutId, User $actor, string $reason): void
    {
        DB::transaction(function () use ($payoutId, $actor, $reason): void {
            $peek = AccountCreditPayout::query()->findOrFail($payoutId, ['id', 'company_id']);
            $company = Company::query()->where('id', $peek->company_id)->lockForUpdate()->firstOrFail();
            Gate::forUser(User::query()->findOrFail($actor->id))->authorize('manageCredit', $company);
            $payout = AccountCreditPayout::query()->where('id', $payoutId)->lockForUpdate()->firstOrFail();
            if ($payout->status !== CreditPayoutStatus::Pending->value) {
                throw new CreditRefused('payout_not_pending', 'This payout is already '.$payout->status.'.', 409);
            }
            $this->fail($company, $payout, trim($reason) === '' ? 'payout_rejected' : trim($reason), $actor->id);
        });
    }

    /** What can still go back to a card payment. */
    public function refundableMinor(Payment $source): int
    {
        $live = (int) AccountCreditPayout::query()->where('source_payment_id', $source->id)
            ->where('status', CreditPayoutStatus::Pending->value)->sum('amount_minor');

        // Processing payouts already have their pending refund row, counted here.
        return max(0, $source->amount_minor - Refunds::refundedOrPendingMinor($source->id) - $live);
    }

    private function fail(Company $company, AccountCreditPayout $payout, string $reason, ?int $actorUserId): void
    {
        $before = $payout->status;
        $payout->forceFill(['status' => CreditPayoutStatus::Failed->value, 'completed_at' => now()])->save();
        $this->ledger->append($company, $payout->event_key.':reversal', [[
            'type' => CreditMovementType::Reversal, 'amount' => $payout->amount_minor, 'reason' => 'payout_not_paid', 'note' => mb_substr($reason, 0, 500),
        ]], $actorUserId);
        $this->audit->record($company->id, 'account_credit_payout', $payout->id, ['status' => $before], ['status' => 'failed'], $actorUserId, $reason);
    }
}
