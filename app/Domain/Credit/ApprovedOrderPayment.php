<?php

namespace App\Domain\Credit;

use App\Domain\Billing\CardIntent;
use App\Domain\Billing\CardPayments;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 05.2 §18.1: a prepaid trade order is paid only after it is approved —
 * no 48-hour card authorisation. Approval moves it to `pending_payment`;
 * the buyer then has CreditExpiry::PAYMENT_WINDOW_HOURS to authorise the
 * card for the order total. Recording the authorisation confirms the
 * order in one transaction under the CreditOrderLocks order, so a reaper
 * racing it either sees the authorisation (and leaves the order) or has
 * already cancelled it (and this refuses). Capture is the caller's, after
 * commit, exactly as at checkout (07 §6.4, 04 §4.4).
 */
final class ApprovedOrderPayment
{
    /** @throws CreditRefused */
    public function assertPayable(Order $order, User $buyer): void
    {
        if ($order->company_id === null || $order->user_id !== $buyer->id) {
            throw new CreditRefused('not_found', 'Order not found.', 404);
        }
        if ($order->status !== 'pending_payment' || $order->payment_status !== 'unpaid') {
            throw new CreditRefused('order_not_payable', 'This order is not waiting for payment.', 409);
        }
        if (now()->greaterThanOrEqualTo($this->deadline($order))) {
            throw new CreditRefused('payment_window_closed', 'The time to pay for this order has ended. Place the order again.', 409);
        }
    }

    /** Two hours from the last approval (05.2 §18.1). */
    public function deadline(Order $order): Carbon
    {
        $approvedAt = OrderApprovalRequest::query()->where('order_id', $order->id)
            ->where('status', ApprovalStatus::Approved->value)->max('decided_at');
        $start = $approvedAt === null ? ($order->placed_at ?? now()) : Carbon::parse((string) $approvedAt);

        return $start->copy()->addHours(CreditExpiry::PAYMENT_WINDOW_HOURS);
    }

    /**
     * Records the authorised card against the order and confirms it.
     *
     * @throws CreditRefused
     */
    public function confirm(int $orderId, User $buyer, CardIntent $intent): Order
    {
        return (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($orderId, $buyer, $intent): Order {
            $locked = (new CreditOrderLocks)->lock($orderId);
            $order = $locked['order'];
            $this->assertPayable($order, $buyer);
            (new CreditGate)->assertCanOrder($locked['company'], $buyer->id, 'card');
            if ($intent->amountMinor !== $order->total_gross_minor || ! $intent->isAuthorised()) {
                throw new CreditRefused('payment_amount_mismatch', 'The card authorisation does not match this order total.', 409);
            }

            CardPayments::recordAuthorisation($order->id, $order->company_id, $intent);
            $order->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
            (new CreditAudit)->orderStatus((int) $order->company_id, $order->id, 'pending_payment', 'confirmed', $buyer->id, 'card_authorised_after_approval');

            DB::afterCommit(fn () => event(new OrderPlaced($orderId)));
            (new Notifications)->orderConfirmed($order->id);

            return $order;
        }), self::class);
    }
}
