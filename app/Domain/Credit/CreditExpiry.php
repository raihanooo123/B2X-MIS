<?php

namespace App\Domain\Credit;

use App\Domain\Collection\CollectionSlots;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\MovementAttribution;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\PartialCancellations;
use App\Models\CollectionBooking;
use App\Models\CollectionSlot;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationLine;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\StockAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 05.2 §18.2 — the coordinated reaper, run every minute
 * (`credit:expire-approvals`), and the one way an unsettled trade order
 * awaiting approval or payment is cancelled (rejection uses it too).
 *
 *   - awaiting_approval past a pending request's 48-hour `expires_at`;
 *   - pending_payment (a prepaid order after approval) past 2 hours from
 *     its last approval.
 *
 * Candidates are found without locks, then each order is processed in its
 * own transaction under CreditOrderLocks and re-checked: a job repeated
 * after a crash, or racing a decision or payment, finds a terminal or
 * settled order and does nothing. An order with an invoice, a live card
 * authorisation or capture, or anything dispatched is never expired; card
 * ambiguity is Stripe reconciliation's, never a speculative refund.
 */
final class CreditExpiry
{
    public const PAYMENT_WINDOW_HOURS = 2;

    public function __construct(
        private readonly DeallocationService $deallocation = new DeallocationService,
        private readonly CollectionSlots $slots = new CollectionSlots,
        private readonly Notifications $notifications = new Notifications,
        private readonly CreditNotices $notices = new CreditNotices,
        private readonly CreditAudit $audit = new CreditAudit,
    ) {}

    /** @return int orders cancelled */
    public function sweep(): int
    {
        $now = now();
        $approvalExpired = OrderApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending->value)->where('expires_at', '<=', $now)
            ->pluck('order_id');
        $paymentExpired = Order::query()
            ->whereNotNull('company_id')->where('status', 'pending_payment')
            ->whereRaw("coalesce((SELECT max(r.decided_at) FROM order_approval_requests r WHERE r.order_id = orders.id AND r.status = 'approved'), orders.placed_at) <= ?", [$now->copy()->subHours(self::PAYMENT_WINDOW_HOURS)])
            ->pluck('id');

        $orderIds = $approvalExpired->merge($paymentExpired)->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        $cancelled = 0;
        foreach ($orderIds as $orderId) {
            $cancelled += (new DeadlockRetryPolicy)->run(fn () => DB::transaction(fn (): int => $this->expireOne($orderId)), self::class);
        }

        return $cancelled;
    }

    private function expireOne(int $orderId): int
    {
        $locked = (new CreditOrderLocks)->lock($orderId);
        $order = $locked['order'];
        $now = now();

        $reason = null;
        if ($order->status === 'awaiting_approval' && OrderApprovalRequest::query()->where('order_id', $order->id)
            ->where('status', ApprovalStatus::Pending->value)->where('expires_at', '<=', $now)->exists()) {
            $reason = 'approval_expired';
        } elseif ($order->status === 'pending_payment') {
            $approvedAt = OrderApprovalRequest::query()->where('order_id', $order->id)
                ->where('status', ApprovalStatus::Approved->value)->max('decided_at');
            $start = $approvedAt === null ? $order->placed_at : Carbon::parse((string) $approvedAt);
            if ($start !== null && $now->greaterThanOrEqualTo($start->copy()->addHours(self::PAYMENT_WINDOW_HOURS))) {
                $reason = 'payment_expired';
            }
        }

        if ($reason === null || $this->settled($order)) {
            return 0;
        }

        $this->cancelLocked($locked, $reason, null, 'system');

        return 1;
    }

    /**
     * Cancels an unsettled trade order whose rows the caller has locked in
     * CreditOrderLocks order. Releases only what is live: allocations, a
     * booked collection place, a `held` credit hold. Pending requests end
     * `expired` (reaper) or `rejected` (a decision).
     *
     * @param  array{company: Company, order: Order, slot: ?CollectionSlot, booking: ?CollectionBooking}  $locked
     * @param  'system'|'customer'|'staff'  $initiatedBy
     *
     * @throws CreditRefused
     */
    public function cancelLocked(array $locked, string $reason, ?int $actorUserId, string $initiatedBy): void
    {
        $order = $locked['order'];
        if ($order->status === 'cancelled') {
            return;
        }
        if (! in_array($order->status, ['awaiting_approval', 'pending_payment'], true) || $this->settled($order)) {
            throw new CreditRefused('order_settled', 'This order has been paid, invoiced or sent, so it cannot be cancelled here.', 409);
        }

        $allocationIds = array_values(StockAllocation::query()
            ->whereIn('order_line_id', OrderLine::query()->where('order_id', $order->id)->select('id'))
            ->whereIn('status', ['allocated', 'picked'])->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all());
        if ($allocationIds !== []) {
            $this->deallocation->deallocateWithinTransaction($allocationIds, new MovementAttribution(
                $actorUserId, 'order_cancelled', "Order {$order->order_number} {$reason} (05.2 §18.2)",
            ));
        }

        // An unfunded request never booked its place (§18.1), so only a live booking gives one back.
        if ($locked['booking'] !== null && $locked['slot'] !== null && $locked['booking']->status === 'booked') {
            $locked['booking']->forceFill(['status' => 'cancelled'])->save();
            $this->slots->releaseLocked($locked['slot']);
        }

        $holdReleased = 0;
        $hold = CreditHold::query()->where('order_id', $order->id)->where('status', 'held')->first();
        if ($hold !== null) {
            $holdReleased = $hold->amount_minor;
            $hold->forceFill(['status' => 'released', 'released_at' => now()])->save();
            Company::query()->where('id', $locked['company']->id)->decrement('credit_held_minor', $holdReleased);
        }

        $cancelledNet = 0;
        $cancelledTax = 0;
        $lines = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('id')->get() as $line) {
            $remaining = $line->base_qty - $line->dispatched_base_qty - $line->cancelled_base_qty;
            if ($remaining <= 0) {
                continue;
            }
            $net = PartialCancellations::billable($line->line_net_minor, $line->cancelled_base_qty, $line->base_qty);
            $tax = PartialCancellations::billable($line->line_tax_minor, $line->cancelled_base_qty, $line->base_qty);
            $cancelledNet += $net;
            $cancelledTax += $tax;
            $lines[] = [$line->id, intdiv($remaining, $line->pack_base_units), $remaining, $net, $tax];
            $line->forceFill(['cancelled_base_qty' => $line->cancelled_base_qty + $remaining])->save();
        }

        $before = $order->status;
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_fee_minor' => 0])->save();

        $cancellation = OrderCancellation::query()->create([
            'order_id' => $order->id,
            'kind' => 'whole',
            'initiated_by' => $initiatedBy,
            'actor_user_id' => $actorUserId,
            'customer_notified_at' => now(),
            'reason_code' => $reason,
            'cancelled_net_minor' => $cancelledNet,
            'cancelled_tax_minor' => $cancelledTax,
            'cancelled_gross_minor' => $cancelledNet + $cancelledTax,
            'delivery_refund_net_minor' => $order->shipping_net_minor,
            'delivery_refund_tax_minor' => $order->shipping_tax_minor,
            'credit_hold_reduction_minor' => $holdReleased,
        ]);
        foreach ($lines as [$lineId, $packQty, $baseQty, $net, $tax]) {
            OrderCancellationLine::query()->create([
                'order_cancellation_id' => $cancellation->id,
                'order_line_id' => $lineId,
                'cancelled_pack_qty' => $packQty,
                'cancelled_base_qty' => $baseQty,
                'line_net_minor' => $net,
                'line_tax_minor' => $tax,
                'line_gross_minor' => $net + $tax,
            ]);
        }

        $terminal = $reason === 'approval_expired' || $reason === 'payment_expired' ? ApprovalStatus::Expired : ApprovalStatus::Rejected;
        foreach (OrderApprovalRequest::query()->where('order_id', $order->id)->where('status', ApprovalStatus::Pending->value)->orderBy('id')->get() as $request) {
            $request->forceFill([
                'status' => $terminal->value,
                'decided_at' => now(),
                'decided_by_user_id' => $terminal === ApprovalStatus::Expired ? null : $actorUserId,
                'decision_reason' => $terminal === ApprovalStatus::Expired ? $reason : ($request->decision_reason ?? $reason),
            ])->save();
            $this->audit->decision($request, $terminal === ApprovalStatus::Expired ? null : $actorUserId);
            $this->notices->decided($request);
        }

        $this->audit->orderStatus($locked['company']->id, $order->id, $before, 'cancelled', $actorUserId, $reason);
        $this->notifications->orderCancelled($order->id);
    }

    /** Paid, invoiced, being paid, or partly sent: never expired or rejected. */
    private function settled(Order $order): bool
    {
        return Invoice::query()->where('order_id', $order->id)->where('status', '<>', 'void')->exists()
            || Payment::query()->where('order_id', $order->id)->where('type', 'payment')->where('gateway', '<>', 'internal')
                ->whereIn('status', ['authorized', 'captured', 'part_refunded', 'refunded'])->exists()
            || OrderLine::query()->where('order_id', $order->id)->where('dispatched_base_qty', '>', 0)->exists();
    }
}
