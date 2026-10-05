<?php
namespace App\Domain\Credit;
use App\Domain\Collection\CollectionSlots;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Billing\InvoiceService;
use App\Models\CollectionBooking;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\OrderLine;
use App\Models\StockAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class TradeApprovals
{
    /** Caller owns company lock and new order transaction; expiry is never reset. */
    public function request(Order $order, ApprovalKind $kind): OrderApprovalRequest
    {
        $request = OrderApprovalRequest::query()->firstOrCreate(['order_id' => $order->id, 'approval_kind' => $kind->value],
            ['company_id' => $order->company_id, 'requested_by_user_id' => $order->user_id,
                'order_gross_minor' => $order->total_gross_minor, 'requested_at' => now(), 'expires_at' => now()->addHours(48)]);
        (new CreditNotices)->pending($request);
        return $request;
    }
    public function decide(int $requestId, User $actor, bool $approve, ?string $reason = null): OrderApprovalRequest
    {
        $source = OrderApprovalRequest::query()->findOrFail($requestId);
        Gate::forUser($actor)->authorize('decide', $source);
        return DB::transaction(function () use ($source, $actor, $approve, $reason): OrderApprovalRequest {
            // For an unfunded request, reserve BEFORE aggregate rows are locked.
            $company = Company::query()->where('id', $source->company_id)->lockForUpdate()->firstOrFail();
            $current = OrderApprovalRequest::query()->findOrFail($source->id);
            Gate::forUser($actor->fresh() ?? $actor)->authorize('decide', $current);
            if ($current->status !== 'pending') {
                if ($current->status === ($approve ? 'approved' : 'rejected') && $current->decided_by_user_id === $actor->id) { return $current; }
                throw new CreditRefused('approval_not_pending', 'This request has already been decided.', 409);
            }
            if (now()->greaterThanOrEqualTo($current->expires_at)) { throw new CreditRefused('approval_expired', 'This approval request has expired.', 409); }
            $order = Order::query()->findOrFail($current->order_id);
            if ($order->status !== 'awaiting_approval') { throw new CreditRefused('order_not_pending', 'The order is no longer awaiting approval.', 409); }
            if ($approve) {
                (new CreditGate)->assertCanOrder($company, $order->user_id, (string) $order->payment_method);
                if ($current->approval_kind === 'credit_exception') { $this->fund($company, $order); }
            }
            $locked = (new CreditOrderLocks)->lock($order->id);
            $order = $locked['order'];
            $current = OrderApprovalRequest::query()->findOrFail($current->id);
            $current->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'decided_by_user_id' => $actor->id, 'decision_reason' => $reason])->save();
            (new CreditAudit)->decision($current, $actor->id);
            if (! $approve) {
                (new CreditExpiry)->cancelLocked($locked, 'buyer_rejected', $actor->id);
            } elseif (! OrderApprovalRequest::query()->where('order_id', $order->id)->where('status', 'pending')->exists()) {
                $due = $order->total_gross_minor - (int) $order->account_credit_applied_minor;
                $order->forceFill(['status' => $order->payment_method === 'card' && $due > 0 ? 'pending_payment' : 'confirmed',
                    'payment_status' => $due === 0 ? 'paid' : $order->payment_status,
                    'confirmed_at' => $order->payment_method === 'card' && $due > 0 ? null : now()])->save();
                if ($order->status === 'confirmed') { $this->confirmed($order); }
            }
            (new CreditNotices)->decided($current);
            return $current->refresh();
        });
    }
    private function fund(Company $company, Order $order): void
    {
        $amount = $order->total_gross_minor - (int) $order->account_credit_applied_minor;
        if ((new CreditGate)->available($company) < $amount) { throw new CreditRefused('credit_still_short', 'Raise the credit limit or let the buyer choose prepayment before approving funding.'); }
        // A funding exception has no allocations/hold. Membership and product state are live.
        $booking = CollectionBooking::query()->where('order_id', $order->id)->first();
        $slot = $booking === null ? null : (new CollectionSlots)->lockAvailable($booking->collection_slot_id);
        $location = $slot?->location_id ?? Location::query()->where('is_default', true)->where('is_sellable', true)->sole()->id;
        $lines = OrderLine::query()->with('sku')->where('order_id', $order->id)->orderBy('id')->get();
        $allocations = [];
        foreach ($lines as $line) {
            $sku = $line->sku;
            if ($sku === null || ! $sku->is_active) { throw new CreditRefused('product_changed', 'An ordered SKU is no longer available. Start a new order.'); }
            if (! $sku->is_stock_tracked) { continue; }
            $allocations[] = new AllocationLine($line->id, $line->sku_id, (int) $location, null, $line->base_qty, selectBatch: $sku->tracking_mode === 'batch');
        }
        if ($allocations !== []) { (new AllocationService)->allocateWithinTransaction(null, 0, $allocations); }
        if ($slot !== null && $booking !== null && $booking->status === 'cancelled') {
            (new CollectionSlots)->bookLocked($slot); $booking->forceFill(['status' => 'booked'])->save();
        }
        if ($amount > 0) {
            CreditHold::query()->create(['company_id' => $company->id, 'order_id' => $order->id, 'amount_minor' => $amount, 'status' => 'held', 'held_at' => now()]);
            Company::query()->where('id', $company->id)->increment('credit_held_minor', $amount);
        }
    }
    public function confirmed(Order $order): void
    {
        DB::afterCommit(fn () => event(new OrderPlaced($order->id)));
        (new Notifications)->orderConfirmed($order->id);
        (new InvoiceService)->whenPlaced($order->id);
    }
    /** Buyer can opt for prepay on an unfunded exception without bypassing buyer approval. */
    public function choosePrepay(int $orderId, User $buyer): Order
    {
        return DB::transaction(function () use ($orderId, $buyer): Order {
            $source = Order::query()->findOrFail($orderId);
            if ($source->user_id !== $buyer->id) { abort(403); }
            $company = Company::query()->where('id', $source->company_id)->lockForUpdate()->firstOrFail();
            (new CreditGate)->assertCanOrder($company, $buyer->id, 'card');
            $request = OrderApprovalRequest::query()->where('order_id', $orderId)->where('approval_kind','credit_exception')->firstOrFail();
            if ($request->status !== 'pending' || now()->greaterThanOrEqualTo($request->expires_at)) { throw new CreditRefused('request_not_pending', 'The funding request is no longer pending.', 409); }
            $source->forceFill(['payment_method' => 'card', 'payment_status' => 'unpaid'])->save();
            $this->fundPrepay($company, $source);
            $request->forceFill(['status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $buyer->id, 'decision_reason' => 'buyer_elected_prepay'])->save();
            (new CreditAudit)->decision($request, $buyer->id);
            if (! OrderApprovalRequest::query()->where('order_id', $orderId)->where('status','pending')->exists()) {
                $source->forceFill(['status' => 'pending_payment'])->save();
            }
            return $source;
        });
    }
    private function fundPrepay(Company $company, Order $order): void
    {
        // Reserve the same immutable order without requiring company credit.
        $booking = CollectionBooking::query()->where('order_id', $order->id)->first();
        $slot = $booking === null ? null : (new CollectionSlots)->lockAvailable($booking->collection_slot_id);
        $location = $slot?->location_id ?? Location::query()->where('is_default',true)->where('is_sellable',true)->sole()->id;
        $lines = OrderLine::query()->with('sku')->where('order_id',$order->id)->orderBy('id')->get();
        $allocations = [];
        foreach ($lines as $line) {
            if ($line->sku?->is_stock_tracked) { $allocations[] = new AllocationLine($line->id,$line->sku_id,(int)$location,null,$line->base_qty, selectBatch: $line->sku->tracking_mode === 'batch'); }
        }
        if ($allocations !== []) { (new AllocationService)->allocateWithinTransaction(null,0,$allocations); }
        if ($slot !== null && $booking !== null) { (new CollectionSlots)->bookLocked($slot); $booking->forceFill(['status'=>'booked'])->save(); }
    }
}
