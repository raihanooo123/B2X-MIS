<?php

namespace App\Domain\Returns;

use App\Domain\Billing\Refunds;
use App\Domain\Billing\RefundSettlement;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\DeliveryAddress;
use App\Domain\Reference\NumberSequenceService;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Rma;
use App\Models\RmaLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 05.4 §13.6 — a consumer return is settled.
 *
 *   - From `inspected`; or from `awaiting_goods` when the customer has given
 *     proof of sending — the refund is owed from the proof whether or not
 *     the parcel arrives (reg. 34(5), §13.5), so the requested quantity is
 *     refunded, with no deduction (nothing was inspected).
 *   - Resolution: a cancellation is refunded (`credit_note`). Faulty goods
 *     within 30 days of possession are refunded in full (CRA s.22, §13.4);
 *     after 30 days the customer chooses repair or replacement; staff
 *     record any override or failed/refused remedy before a refund.
 *     Repair and replacement settle the return with no refund. A
 *     replacement creates its zero-value replacement order here, for the
 *     quantities accepted at inspection (ReplacementOrders, 05.4 §14) —
 *     unless staff already sent an advance replacement, which is reused.
 *     A shortfall leaves the return as it was.
 *   - Refund (RefundCalculator): goods actually refunded, less diminished
 *     value; the outbound delivery — for a cancellation, the standard charge
 *     (02 §26.3) and only when nothing of the order is kept; for faulty goods
 *     within 30 days, the whole charge when the whole order is rejected —
 *     once per order.
 *   - In one transaction: the credit note against the order's receipt
 *     (company NULL, 02 §14.5.3), `order_lines.returned_base_qty`, and a
 *     pending refund row on the payment it reverses. **Never an account
 *     balance**: a consumer has no account, and is refunded the way they
 *     paid (CCR reg. 34(7)).
 *   - After commit: the card refund at the gateway (RefundSettlement); a
 *     BACS refund waits for accounts to pay and record it. `rma.resolved`.
 */
final class ReturnResolution
{
    public function __construct(
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
        private readonly ReplacementOrders $replacements = new ReplacementOrders,
    ) {}

    /**
     * @param  string|null  $resolutionType  for faulty goods after 30 days: `repair`, `replacement` or `credit_note`
     * @param  DeliveryAddress|null  $replacementAddress  a replacement sent somewhere other than the original delivery address (R10)
     *
     * @throws ReturnActionRefusedException
     */
    public function resolve(int $rmaId, int $staffUserId, ?string $resolutionType = null, ?string $overrideBasis = null, ?string $remedyOutcome = null, ?string $remedyReason = null, ?DeliveryAddress $replacementAddress = null): Rma
    {
        /** @var array{rma: Rma, refund_id: int|null} $outcome */
        $outcome = DB::transaction(fn () => $this->resolveWithinTransaction($rmaId, $staffUserId, $resolutionType, $overrideBasis, $remedyOutcome, $remedyReason, $replacementAddress));

        if ($outcome['refund_id'] !== null) {
            (new RefundSettlement($this->notifications))->settle([$outcome['refund_id']]);
        }

        return $outcome['rma']->fresh(['lines']) ?? $outcome['rma'];
    }

    /**
     * @return array{rma: Rma, refund_id: int|null}
     */
    private function resolveWithinTransaction(int $rmaId, int $staffUserId, ?string $resolutionType, ?string $overrideBasis, ?string $remedyOutcome, ?string $remedyReason, ?DeliveryAddress $replacementAddress): array
    {
        $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
        if ($rma->company_id !== null) {
            throw new ReturnActionRefusedException('trade_return', 'Trade returns are settled under 05.4 §7.5, not here.');
        }

        $onProof = $rma->status === 'awaiting_goods' && $rma->goods_sent_at !== null && $rma->return_reason === 'consumer_cancellation';
        $finalReject = $rma->status === 'resolved' && in_array($rma->resolution_type, ['repair', 'replacement'], true) && $resolutionType === 'credit_note' && in_array($remedyOutcome, ['failed', 'refused'], true);
        if ($rma->credit_note_id !== null || ($rma->resolved_at !== null && ! $finalReject)) {
            throw new ReturnActionRefusedException('already_settled', 'This return has already been settled.');
        }
        if ($rma->status !== 'inspected' && ! $onProof && ! $finalReject) {
            throw new ReturnActionRefusedException('not_ready', "Return {$rma->rma_number} cannot be settled yet (it is {$rma->status}).");
        }

        $fault = $rma->return_reason !== 'consumer_cancellation';
        $withinReject = $fault && FaultReports::withinRejectPeriod($rma);
        $type = $this->resolutionType($fault, $withinReject, $resolutionType ?? ($fault && ! $withinReject ? $rma->resolution_type : null));
        if ($fault && ! $withinReject) {
            $record = json_decode($rma->internal_note ?? '{}', true);
            $choice = is_array($record) ? ($record['customer_choice'] ?? null) : null;
            if (! in_array($choice, ['repair', 'replacement'], true)) {
                throw new ReturnActionRefusedException('customer_choice_required', 'Record the customer repair or replacement choice first.');
            }
            $reason = trim($remedyReason ?? '');
            if ($type === 'credit_note' && (! in_array($remedyOutcome, ['failed', 'refused'], true) || $reason === '')) {
                throw new ReturnActionRefusedException('remedy_outcome_required', 'Record why repair/replacement failed or was refused before refunding.');
            }
            if ($type !== 'credit_note' && $type !== $choice && (! in_array($overrideBasis, ['impossible', 'disproportionate'], true) || $reason === '')) {
                throw new ReturnActionRefusedException('override_reason_required', 'Override only when impossible or disproportionate, with a reason.');
            }
            if ($type === 'credit_note' || $type !== $choice) {
                $record['decisions'][] = ['resolution_type' => $type, 'override_basis' => $overrideBasis, 'remedy_outcome' => $remedyOutcome, 'reason' => $reason, 'staff_user_id' => $staffUserId, 'at' => now()->toIso8601String()];
                $rma->internal_note = json_encode($record, JSON_THROW_ON_ERROR);
            }
        }

        $lines = RmaLine::query()->where('rma_id', $rma->id)->orderBy('line_no')->lockForUpdate()->get();
        $refundable = [];
        foreach ($lines as $line) {
            $refundable[$line->id] = $onProof ? $line->requested_base_qty : $line->restocked_base_qty + $line->quarantined_base_qty + $line->written_off_base_qty;
        }

        // 05.4 §14: the replacement order itself, unless an advance one exists.
        if ($type === 'replacement' && $rma->replacement_order_id === null) {
            $this->replacements->createWithinTransaction($rma, $refundable, $staffUserId, $replacementAddress);
        }

        $refundId = null;
        if ($type === 'credit_note') {
            $order = Order::query()->lockForUpdate()->findOrFail($rma->order_id);
            $orderLines = OrderLine::query()->whereIn('id', $lines->pluck('order_line_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                $refunded = min($refundable[$line->id], $line->requested_base_qty);
                if ($refunded > 0) {
                    ($orderLines->get($line->order_line_id) ?? throw new RuntimeException("Order line {$line->order_line_id} of {$rma->rma_number} is missing."))
                        ->increment('returned_base_qty', $refunded);
                }
            }

            $calc = RefundCalculator::calculate(
                array_values($lines->map(fn (RmaLine $l) => [
                    'goods_net_minor' => $l->line_goods_net_minor,
                    'requested_base_qty' => $l->requested_base_qty,
                    'refundable_base_qty' => $refundable[$l->id],
                    'diminished_value_minor' => $onProof || $fault ? 0 : $l->diminished_value_minor,
                    'tax_rate_bp' => $l->tax_rate_bp,
                ])->all()),
                $this->deliveryRefundNet($rma, $order, $fault, $withinReject),
                (int) ($order->shipping_tax_rate_bp ?? 0),
            );

            foreach ($lines->values() as $i => $line) {
                $line->update(['line_refund_net_minor' => $calc['lines'][$i]['net_minor'], 'line_refund_tax_minor' => $calc['lines'][$i]['tax_minor']]);
            }

            $rma->fill([
                'refund_net_minor' => $calc['net_minor'],
                'refund_tax_minor' => $calc['tax_minor'],
                'refund_gross_minor' => $calc['gross_minor'],
                'delivery_refund_net_minor' => $calc['delivery_net_minor'],
                'delivery_refund_tax_minor' => $calc['delivery_tax_minor'],
            ]);

            if ($calc['gross_minor'] > 0) {
                $refundId = $this->refundRow($order, $calc['gross_minor']);
                $rma->refund_payment_id = $refundId;
                $rma->credit_note_id = $this->creditNote($rma, $order, $calc)->id;
            }
        }

        $partial = $lines->contains(fn (RmaLine $l) => $refundable[$l->id] < $l->requested_base_qty);
        $rma->fill([
            'status' => $partial && $type === 'credit_note' ? 'partially_resolved' : 'resolved',
            // A return that sent a replacement stays `replacement` even when a
            // failed replacement is later refunded (§13.4): rmas_replacement_chk
            // ties the replacement order to that type, and the refund is on
            // record through its credit note, refund fields and decision log.
            'resolution_type' => $rma->replacement_order_id !== null ? 'replacement' : $type,
            'resolved_at' => now(),
            'handled_by_user_id' => $staffUserId,
        ]);
        $rma->save();

        $this->notifications->rmaResolved($rma->id);

        return ['rma' => $rma, 'refund_id' => $refundId];
    }

    private function resolutionType(bool $fault, bool $withinReject, ?string $requested): string
    {
        if (! $fault || $withinReject) {
            // A cancellation, or the short-term right to reject: a refund (§13.4).
            if ($requested !== null && $requested !== 'credit_note') {
                throw new ReturnActionRefusedException('refund_required', $fault
                    ? 'Within 30 days of delivery, faulty goods are refunded in full.'
                    : 'A cancellation is refunded.');
            }

            return 'credit_note';
        }

        if (! in_array($requested, ['repair', 'replacement', 'credit_note'], true)) {
            throw new ReturnActionRefusedException('resolution_required', 'More than 30 days after delivery: Record the customer choice of repair or replacement, or the failed/refused remedy before refunding.');
        }

        return $requested;
    }

    /**
     * 05.4 §13.5: once per order, and only when nothing of the order is kept
     * (every line returned in full). A cancellation refunds the standard
     * charge; faulty goods within 30 days the whole charge.
     */
    private function deliveryRefundNet(Rma $rma, Order $order, bool $fault, bool $withinReject): int
    {
        if ($fault && ! $withinReject) {
            return 0;
        }

        $alreadyRefunded = Rma::query()->where('order_id', $order->id)->where('id', '<>', $rma->id)->where('delivery_refund_net_minor', '>', 0)->exists();
        $everythingBack = ! OrderLine::query()->where('order_id', $order->id)->whereColumn('returned_base_qty', '<', 'base_qty')->exists();
        if ($alreadyRefunded || ! $everythingBack || ! in_array($order->status, ['dispatched', 'completed'], true)) {
            return 0;
        }

        return $fault ? $order->shipping_net_minor : (int) ($order->standard_shipping_net_minor ?? 0);
    }

    /** A pending refund on the payment the customer made, up to what is left of it. */
    private function refundRow(Order $order, int $grossMinor): ?int
    {
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->where('type', 'payment')
            ->whereIn('status', ['captured', 'part_refunded'])
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        if ($payment === null) {
            return null; // Nothing was paid, so nothing is refunded.
        }

        $amount = min($grossMinor, $payment->amount_minor - Refunds::refundedOrPendingMinor($payment->id));

        return $amount > 0 ? Refunds::recordPending($payment, $amount)->id : null;
    }

    /**
     * @param  array{net_minor: int, tax_minor: int, gross_minor: int, delivery_net_minor: int, delivery_tax_minor: int}  $calc
     */
    private function creditNote(Rma $rma, Order $order, array $calc): CreditNote
    {
        $receipt = Invoice::query()->where('order_id', $order->id)->whereNull('company_id')->where('status', '<>', 'void')->orderBy('id')->first();

        return CreditNote::query()->create([
            'credit_note_number' => $this->numbers->next('credit_note_number'),
            'order_id' => $order->id,
            'invoice_id' => $receipt?->id,
            'rma_id' => $rma->id,
            'reason' => 'return',
            'subtotal_net_minor' => $calc['net_minor'] + $calc['delivery_net_minor'],
            'tax_minor' => $calc['tax_minor'] + $calc['delivery_tax_minor'],
            'total_gross_minor' => $calc['gross_minor'],
            'issued_at' => now(),
        ]);
    }
}
