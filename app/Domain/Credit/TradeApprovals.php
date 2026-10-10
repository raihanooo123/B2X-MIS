<?php

namespace App\Domain\Credit;

use App\Domain\Billing\InvoiceService;
use App\Domain\Collection\CollectionSlots;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\PaymentMethod;
use App\Models\CollectionBooking;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §10, §18.1 — trade order approvals.
 *
 * Two kinds, one request per order and kind (02 §31.1):
 *
 *   buyer_limit       full gross above the buyer's order limit, or a buyer
 *                     who always needs approval. Funded: stock, collection
 *                     place and on-account credit are held for the 48 hours.
 *                     Decided by another owner/approver of the company.
 *   credit_exception  on account, above available credit. Unfunded: nothing
 *                     is reserved until accounts raises the limit and
 *                     approves (funding happens then), or the buyer elects
 *                     to pay in advance. Decided by accounts/admin only.
 *
 * Both share one `expires_at`, 48 hours from submission, never extended.
 * When none is pending, an approved order moves on: a card order to
 * `pending_payment` (2 hours to pay, CreditExpiry), anything else to
 * `confirmed`. A rejection cancels the order through CreditExpiry.
 */
final class TradeApprovals
{
    public const WINDOW_HOURS = 48;

    public function __construct(
        private readonly CreditGate $gate = new CreditGate,
        private readonly CreditAudit $audit = new CreditAudit,
        private readonly CreditNotices $notices = new CreditNotices,
        private readonly CollectionSlots $slots = new CollectionSlots,
    ) {}

    /**
     * Inside checkout's transaction, the company already locked. The
     * expiry is taken once, at first submission.
     */
    public function request(Order $order, ApprovalKind $kind): OrderApprovalRequest
    {
        $first = OrderApprovalRequest::query()->where('order_id', $order->id)->min('requested_at');
        $requestedAt = $first === null ? now() : Carbon::parse((string) $first);

        $request = OrderApprovalRequest::query()->firstOrCreate(
            ['order_id' => $order->id, 'approval_kind' => $kind->value],
            [
                'company_id' => $order->company_id,
                'requested_by_user_id' => $order->user_id,
                'order_gross_minor' => $order->total_gross_minor,
                'requested_at' => $requestedAt,
                'expires_at' => $requestedAt->copy()->addHours(self::WINDOW_HOURS),
            ],
        );
        $this->notices->pending($request);

        return $request;
    }

    /**
     * Approve or reject. Authorisation, the request's state and expiry, the
     * buyer's membership and the company's gates are all re-read under the
     * company lock. A repeat of the same decision by the same person
     * returns it unchanged.
     *
     * @throws CreditRefused
     */
    public function decide(int $requestId, User $actor, bool $approve, ?string $reason = null): OrderApprovalRequest
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if (! $approve && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for rejecting the order.']);
        }

        return (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($requestId, $actor, $approve, $reason): OrderApprovalRequest {
            $peek = OrderApprovalRequest::query()->findOrFail($requestId, ['id', 'company_id', 'order_id']);
            // companies → orders → collection_slots first (CreditOrderLocks); funding adds stock after.
            $locks = new CreditOrderLocks;
            $head = $locks->head($peek->order_id);
            $company = $head['company'];
            $order = $head['order'];
            $actor = User::query()->findOrFail($actor->id);
            $request = OrderApprovalRequest::query()->findOrFail($peek->id);
            Gate::forUser($actor)->authorize('decide', $request);

            $outcome = $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected;
            if ($request->status !== ApprovalStatus::Pending->value) {
                if ($request->status === $outcome->value && $request->decided_by_user_id === $actor->id) {
                    return $request;
                }
                throw new CreditRefused('approval_not_pending', 'This request has already been '.$request->status.'.', 409);
            }
            if (now()->greaterThanOrEqualTo($request->expires_at)) {
                throw new CreditRefused('approval_expired', 'This request expired at '.$request->expires_at->toIso8601String().'. The buyer can place the order again.', 409);
            }

            if ($order->status !== 'awaiting_approval') {
                throw new CreditRefused('order_not_awaiting_approval', 'This order is no longer waiting for approval.', 409);
            }

            if ($approve) {
                $this->gate->assertCanOrder($company, $order->user_id, (string) $order->payment_method);
                if ($request->approval_kind === ApprovalKind::CreditException->value) {
                    $this->fund($company, $order);
                }
            }

            $locked = $head + ['booking' => $locks->tail($order->id)];
            $request->forceFill([
                'status' => $outcome->value,
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
                'decision_reason' => $reason,
            ])->save();
            $this->audit->decision($request, $actor->id);
            $this->notices->decided($request);

            if (! $approve) {
                $credit = $request->approval_kind === ApprovalKind::CreditException->value;
                (new CreditExpiry)->cancelLocked($locked, $credit ? 'credit_exception_rejected' : 'approval_rejected', $actor->id, $credit ? 'staff' : 'customer');
            } elseif (! OrderApprovalRequest::query()->where('order_id', $order->id)->where('status', ApprovalStatus::Pending->value)->exists()) {
                $this->release($order, $actor->id);
            }

            return $request->refresh();
        }), self::class);
    }

    /**
     * 05.2 §8.1 row 3: instead of waiting for accounts, the buyer pays this
     * order in advance by card. The shortfall request ends; stock and any
     * collection place are reserved now; a buyer-limit request still
     * applies.
     *
     * @throws CreditRefused
     */
    public function payInAdvance(int $orderId, User $buyer): Order
    {
        return (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($orderId, $buyer): Order {
            $peek = Order::query()->findOrFail($orderId, ['id', 'company_id', 'user_id']);
            if ($peek->company_id === null || $peek->user_id !== $buyer->id) {
                throw new CreditRefused('not_found', 'Order not found.', 404);
            }
            $locks = new CreditOrderLocks;
            $head = $locks->head($orderId);
            $company = $head['company'];
            $order = $head['order'];
            $request = OrderApprovalRequest::query()->where('order_id', $order->id)
                ->where('approval_kind', ApprovalKind::CreditException->value)->first();
            if ($order->status !== 'awaiting_approval' || $request?->status !== ApprovalStatus::Pending->value || now()->greaterThanOrEqualTo($request->expires_at)) {
                throw new CreditRefused('approval_not_pending', 'This order is no longer waiting for a credit decision.', 409);
            }
            $this->gate->assertCanOrder($company, $buyer->id, PaymentMethod::Card->value);

            $order->forceFill(['payment_method' => PaymentMethod::Card->value, 'payment_status' => 'unpaid'])->save();
            $this->reserve($order);

            $locks->tail($order->id);
            $request->forceFill([
                'status' => ApprovalStatus::Rejected->value,
                'decided_at' => now(),
                'decided_by_user_id' => $buyer->id,
                'decision_reason' => 'buyer_paid_in_advance',
            ])->save();
            $this->audit->decision($request, $buyer->id);

            if (! OrderApprovalRequest::query()->where('order_id', $order->id)->where('status', ApprovalStatus::Pending->value)->exists()) {
                $this->release($order, $buyer->id);
            }

            return $order->refresh();
        }), self::class);
    }

    /** Every approval given: on to payment or confirmation. */
    private function release(Order $order, int $actorUserId): void
    {
        $before = $order->status;
        if ($order->payment_method === PaymentMethod::Card->value) {
            $order->forceFill(['status' => 'pending_payment'])->save();
        } else {
            $order->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
            $orderId = $order->id;
            DB::afterCommit(fn () => event(new OrderPlaced($orderId)));
            (new Notifications)->orderConfirmed($order->id);
            (new InvoiceService)->whenPlaced($order->id);
        }
        $this->audit->orderStatus((int) $order->company_id, $order->id, $before, $order->status, $actorUserId, 'approvals_complete');
    }

    /**
     * Accounts approves a shortfall: the credit must now be there. Funding
     * reserves what checkout skipped, between CreditOrderLocks' head and
     * tail (companies → orders → slot held; stock now), then the hold.
     */
    private function fund(Company $company, Order $order): void
    {
        $amount = $order->total_gross_minor;
        if ($this->gate->available($company) < $amount) {
            throw new CreditRefused('insufficient_credit', 'Available credit is still below this order. Raise the credit limit first, or reject so the buyer can pay in advance.');
        }

        $this->reserve($order);

        CreditHold::query()->create([
            'company_id' => $company->id,
            'order_id' => $order->id,
            'amount_minor' => $amount,
            'status' => 'held',
            'held_at' => now(),
        ]);
        Company::query()->where('id', $company->id)->increment('credit_held_minor', $amount);
    }

    /** Stock and the chosen collection place, for an order whose checkout reserved neither. */
    private function reserve(Order $order): void
    {
        $booking = CollectionBooking::query()->where('order_id', $order->id)->first();
        $slot = $booking === null ? null : $this->slots->lockAvailable($booking->collection_slot_id);
        $locationId = $slot !== null
            ? $slot->location_id
            : (int) Location::query()->where('is_default', true)->where('is_sellable', true)->value('id');

        $lines = [];
        foreach (OrderLine::query()->with('sku')->where('order_id', $order->id)->orderBy('id')->get() as $line) {
            $sku = $line->sku;
            if ($sku === null || $sku->status !== 'active') {
                throw new CreditRefused('product_unavailable', 'An item on this order is no longer sold. The buyer needs to place a new order.', 409);
            }
            if ($sku->is_stock_tracked) {
                $lines[] = new AllocationLine($line->id, $line->sku_id, $locationId, null, $line->base_qty, selectBatch: $sku->tracking_mode === 'batch');
            }
        }
        if ($lines !== []) {
            (new AllocationService)->allocateWithinTransaction(null, 0, $lines);
        }

        if ($slot !== null && $booking->status !== 'booked') {
            $this->slots->bookLocked($slot);
            $booking->forceFill(['status' => 'booked'])->save();
        }
    }
}
