<?php

namespace App\Domain\Returns;

use App\Domain\Billing\Refunds;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\Payment;
use App\Models\Rma;
use Illuminate\Support\Facades\DB;

/**
 * 05.4 §13.6 — accounts record a consumer refund paid by bank transfer:
 *
 *   - a pending BACS refund (the customer paid by bank transfer) is marked
 *     paid, with the transfer's reference;
 *   - a card refund the gateway refused (`failed`) stays as the record of
 *     that attempt; a new BACS refund for the same amount is recorded and
 *     marked paid, and the return points at it.
 *
 * Never an account balance: a consumer has none.
 */
final class BankRefunds
{
    public function record(int $rmaId, string $reference, int $staffUserId): Rma
    {
        return DB::transaction(function () use ($rmaId, $reference, $staffUserId): Rma {
            $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
            $refund = $rma->refund_payment_id === null ? null : Payment::query()->lockForUpdate()->find($rma->refund_payment_id);

            if ($refund === null) {
                throw new ReturnActionRefusedException('no_refund', "Return {$rma->rma_number} has no refund to record.");
            }
            if ($refund->status === 'captured') {
                throw new ReturnActionRefusedException('already_refunded', "Return {$rma->rma_number} has already been refunded.");
            }
            if ($refund->gateway === 'stripe' && $refund->status === 'pending') {
                throw new ReturnActionRefusedException('card_refund_pending', 'The card refund is still being made. Record a bank transfer only if it fails.');
            }

            if ($refund->status === 'failed') {
                $original = Payment::query()->findOrFail($refund->refunded_payment_id);
                $refund = Refunds::recordPending($original, $refund->amount_minor, 'bacs');
                $rma->refund_payment_id = $refund->id;
            }

            $rma->handled_by_user_id = $staffUserId;
            $rma->save();
            Refunds::markSucceeded($refund->id, mb_substr(trim($reference), 0, 100));

            return $rma;
        });
    }
}
