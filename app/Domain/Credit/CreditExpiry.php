<?php
namespace App\Domain\Credit;
use App\Domain\Collection\CollectionSlots;
use App\Domain\Inventory\DeallocationService;
use App\Domain\Inventory\MovementAttribution;
use App\Domain\Notifications\Notifications;
use App\Models\CollectionBooking;
use App\Models\CollectionSlot;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationLine;
use App\Models\Payment;
use App\Models\StockAllocation;
use Illuminate\Support\Facades\DB;

final class CreditExpiry
{
    public function sweep(): int
    {
        $ids = OrderApprovalRequest::query()->where('status','pending')->where('expires_at','<=',now())->orderBy('expires_at')->pluck('order_id')->all();
        $paymentIds = Order::query()->whereNotNull('company_id')->where('status','pending_payment')->orderBy('id')->pluck('id')->all();
        $count = 0;
        foreach (array_unique([...$ids,...$paymentIds]) as $id) {
            $count += DB::transaction(function () use ($id): int {
                $locked = (new CreditOrderLocks)->lock((int)$id);
                $order = $locked['order'];
                if ($order->status === 'cancelled') { return 0; }
                $requests = OrderApprovalRequest::query()->where('order_id',$order->id)->get();
                $approvalExpired = $order->status === 'awaiting_approval' && $requests->contains(fn (OrderApprovalRequest $a) => $a->status === 'pending' && now()->greaterThanOrEqualTo($a->expires_at));
                $lastDecision = $requests->where('status','approved')->max('decided_at');
                $start = $lastDecision ?? $order->placed_at;
                $paymentExpired = $order->status === 'pending_payment' && $start !== null && now()->greaterThanOrEqualTo(\Carbon\Carbon::parse($start)->addHours(2));
                if (! $approvalExpired && ! $paymentExpired) { return 0; }
                if ($this->settled($order)) { return 0; }
                $this->cancelLocked($locked, $approvalExpired ? 'approval_expired' : 'payment_expired');
                return 1;
            });
        }
        return $count;
    }
    /** @param array{company: Company, order: Order, slot: ?CollectionSlot, booking: ?CollectionBooking} $locked */
    public function cancelLocked(array $locked, string $reason, ?int $actorId = null): void
    {
        $order = $locked['order'];
        if ($order->status === 'cancelled') { return; }
        if ($this->settled($order)) { throw new CreditRefused('order_settled', 'A paid or invoiced order cannot be expired or rejected.',409); }
        $ids = StockAllocation::query()->whereHas('orderLine', fn ($q) => $q->where('order_id',$order->id))
            ->whereIn('status',['allocated','picked'])->pluck('id')->map(fn ($id) => (int)$id)->all();
        if ($ids !== []) { (new DeallocationService)->deallocateWithinTransaction($ids, new MovementAttribution(reasonCode: $reason, actorUserId: $actorId)); }
        if ($locked['booking']?->status === 'booked' && $locked['slot'] !== null) {
            (new CollectionSlots)->releaseLocked($locked['slot']);
            $locked['booking']->forceFill(['status'=>'cancelled'])->save();
        }
        $hold = CreditHold::query()->where('order_id',$order->id)->where('status','held')->first();
        if ($hold !== null) {
            Company::query()->where('id',$locked['company']->id)->decrement('credit_held_minor',$hold->amount_minor);
            $hold->forceFill(['status'=>'released','released_at'=>now()])->save();
        }
        (new CreditLedger)->reverseOrder($locked['company'],$order);
        $cancellation = OrderCancellation::query()->create(['order_id'=>$order->id,'kind'=>'whole',
            'initiated_by'=>$actorId === null ? 'system' : 'customer','actor_user_id'=>$actorId,
            'customer_notified_at'=>now(),'reason_code'=>$reason,'cancelled_net_minor'=>$order->subtotal_net_minor,
            'cancelled_tax_minor'=>$order->tax_minor - $order->shipping_tax_minor,
            'cancelled_gross_minor'=>$order->subtotal_net_minor + $order->tax_minor - $order->shipping_tax_minor,
            'delivery_refund_net_minor'=>0,'delivery_refund_tax_minor'=>0]);
        foreach ($order->lines()->orderBy('id')->get() as $line) {
            OrderCancellationLine::query()->create(['order_cancellation_id'=>$cancellation->id,'order_line_id'=>$line->id,
                'cancelled_pack_qty'=>$line->pack_qty,'cancelled_base_qty'=>$line->base_qty,
                'line_net_minor'=>$line->line_net_minor,'line_tax_minor'=>$line->line_tax_minor,'line_gross_minor'=>$line->line_gross_minor]);
            $line->forceFill(['cancelled_base_qty'=>$line->base_qty])->save();
        }
        $before = $order->status;
        $order->forceFill(['status'=>'cancelled','cancelled_at'=>now()])->save();
        foreach (OrderApprovalRequest::query()->where('order_id',$order->id)->where('status','pending')->get() as $a) {
            $a->forceFill(['status'=>str_contains($reason,'expired') ? 'expired' : 'rejected', 'decided_at'=>now(),'decided_by_user_id'=>$actorId,'decision_reason'=>$reason])->save();
            (new CreditAudit)->decision($a,$actorId);
            (new CreditNotices)->decided($a);
        }
        (new CreditAudit)->status($locked['company']->id,'order',$order->id,$before,'cancelled',$actorId,$reason);
        (new Notifications)->orderCancelled($order->id);
    }
    private function settled(Order $order): bool
    {
        return Invoice::query()->where('order_id',$order->id)->where('status','<>','void')->exists()
            || Payment::query()->where('order_id',$order->id)->where('gateway','<>','internal')->whereIn('status',['authorized','captured','part_refunded','refunded'])->exists()
            || $order->lines()->where('dispatched_base_qty','>',0)->exists();
    }
}
