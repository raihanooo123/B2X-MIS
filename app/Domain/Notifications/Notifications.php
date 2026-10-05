<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Notices\CollectionExpired;
use App\Domain\Notifications\Notices\CreditLimitReached;
use App\Domain\Notifications\Notices\CreditLimitWarning;
use App\Domain\Notifications\Notices\InvoiceDueSoon;
use App\Domain\Notifications\Notices\InvoiceIssued;
use App\Domain\Notifications\Notices\InvoiceOverdue;
use App\Domain\Notifications\Notices\OrderCancelled;
use App\Domain\Notifications\Notices\OrderConfirmed;
use App\Domain\Notifications\Notices\OrderItemsCancelled;
use App\Domain\Notifications\Notices\PayAtCollectionSuspended;
use App\Domain\Notifications\Notices\PaymentReceived;
use App\Domain\Notifications\Notices\RefundFailed;
use App\Domain\Notifications\Notices\RmaApproved;
use App\Domain\Notifications\Notices\RmaNotReceived;
use App\Domain\Notifications\Notices\RmaProofRejected;
use App\Domain\Notifications\Notices\RmaRefundDueSoon;
use App\Domain\Notifications\Notices\RmaRejected;
use App\Domain\Notifications\Notices\RmaReplacementCreated;
use App\Domain\Notifications\Notices\RmaRequested;
use App\Domain\Notifications\Notices\RmaResolved;
use App\Domain\Notifications\Notices\ShipmentDispatched;
use App\Domain\Ordering\PaymentMethod;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Rma;
use App\Models\Shipment;
use App\Models\User;
use App\Support\DisplayTime;

/**
 * The entry points domain code calls: each pairs one notice with its
 * recipients (05.12 §7.1) and hands both to NotificationDispatcher. Safe to
 * call inside a transaction — nothing is queued until it commits.
 *
 * Auth notices, which always go to one known user, are sent through
 * toUser().
 */
final class Notifications
{
    /** 05.12 §5.1.2: usage at or above this share of the limit, in percent. */
    public const CREDIT_WARNING_PERCENT = 80;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher = new NotificationDispatcher,
        private readonly RecipientResolver $recipients = new RecipientResolver,
    ) {}

    public function toUser(Notice $notice, User $user): void
    {
        $this->dispatcher->send($notice, [Recipient::user($user)]);
    }

    /** An address with no account behind it (05.13 §5.1's no-enumeration replies). */
    public function toRecipient(Notice $notice, Recipient $recipient): void
    {
        $this->dispatcher->send($notice, [$recipient]);
    }

    public function orderConfirmed(int $orderId): void
    {
        $order = Order::query()->find($orderId, ['id', 'user_id', 'company_id', 'guest_email']);
        if ($order !== null) {
            $this->dispatcher->send(new OrderConfirmed($orderId), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.12 §5.1 `order.cancelled`: a consumer's cancellation, acknowledged (05.4 §13.2). */
    public function orderCancelled(int $orderId): void
    {
        $order = Order::query()->find($orderId, ['id', 'user_id', 'company_id', 'guest_email']);
        if ($order !== null) {
            $this->dispatcher->send(new OrderCancelled($orderId), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.6 §7A.10 `collection.expired`: a pay-at-collection order cancelled by the sweep. */
    public function collectionExpired(int $orderId): void
    {
        $order = Order::query()->find($orderId, ['id', 'user_id', 'company_id', 'guest_email']);
        if ($order !== null) {
            $this->dispatcher->send(new CollectionExpired($orderId), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.6 §7A.10 `collection.pay_at_collection_suspended`: the customer (public) or the company's owners (trade). */
    public function payAtCollectionSuspended(int $suspensionId, ?int $userId, ?int $companyId, int $noShowCount): void
    {
        $this->dispatcher->send(new PayAtCollectionSuspended($suspensionId, $noShowCount), $this->recipients->payAtCollectionCustomer($userId, $companyId));
    }

    /** 05.10 §2.6: a durable acknowledgement of items cancelled before dispatch. */
    public function orderItemsCancelled(int $cancellationId): void
    {
        $cancellation = OrderCancellation::query()->find($cancellationId, ['id', 'order_id']);
        $order = $cancellation === null ? null : Order::query()->find($cancellation->order_id, ['id', 'user_id', 'company_id', 'guest_email']);
        if ($order !== null) {
            $recipients = $this->recipients->orderCustomer($order);
            if ($order->company_id !== null) {
                $invoice = Invoice::query()->where('order_id', $order->id)->orderBy('id')->first();
                if ($invoice !== null) {
                    $recipients = [...$recipients, ...$this->recipients->invoiceRecipients($invoice)];
                } else {
                    $recipients = [...$recipients, ...$this->recipients->defaultContactAndOwners($order->company_id)];
                }
            }
            $this->dispatcher->send(new OrderItemsCancelled($cancellationId), $recipients);
        }
    }

    /** 05.12 §5.1 `rma.approved`: to the order's customer (05.4 §7.2, §13.3). */
    public function rmaApproved(int $rmaId): void
    {
        $rma = Rma::query()->find($rmaId, ['id', 'order_id']);
        $order = $rma === null ? null : Order::query()->find($rma->order_id, ['id', 'user_id', 'company_id', 'guest_email']);
        if ($order !== null) {
            $this->dispatcher->send(new RmaApproved($rmaId), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.12 §5.1 `rma.proof_rejected`: to the customer, who may upload again (05.4 §13.5). */
    public function rmaProofRejected(int $rmaId, string $reason): void
    {
        $order = $this->rmaOrder($rmaId);
        if ($order !== null) {
            $this->dispatcher->send(new RmaProofRejected($rmaId, $reason), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.12 §5.1 `rma.refund_due_soon`: accounts, once per deadline (05.4 §13.5). */
    public function rmaRefundDueSoon(int $rmaId, string $dueOn): void
    {
        $this->dispatcher->send(new RmaRefundDueSoon($rmaId, $dueOn), $this->recipients->role('accounts'));
    }

    /** 05.12 §5.1 `rma.not_received`: the customer and accounts (05.4 §7.6). */
    public function rmaNotReceived(int $rmaId): void
    {
        $order = $this->rmaOrder($rmaId);
        $customer = $order === null ? [] : $this->recipients->orderCustomer($order);
        $this->dispatcher->send(new RmaNotReceived($rmaId), [...$customer, ...$this->recipients->role('accounts')]);
    }

    /** 05.12 §5.1 `rma.requested`: the handler, accounts (05.4 §13.4). Consumers have no rep. */
    public function rmaRequested(int $rmaId): void
    {
        $this->dispatcher->send(new RmaRequested($rmaId), $this->recipients->role('accounts'));
    }

    /** 05.12 §5.1 `rma.rejected`: to the customer, with the reason. */
    public function rmaRejected(int $rmaId, string $reason): void
    {
        $order = $this->rmaOrder($rmaId);
        if ($order !== null) {
            $this->dispatcher->send(new RmaRejected($rmaId, $reason), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.12 §5.1 `rma.resolved`: to the customer (05.4 §13.6). */
    public function rmaResolved(int $rmaId): void
    {
        $order = $this->rmaOrder($rmaId);
        if ($order !== null) {
            $this->dispatcher->send(new RmaResolved($rmaId), $this->recipients->orderCustomer($order));
        }
    }

    /** 05.4 §14.2 R16 `rma.replacement_created`: to the customer, what is coming and when. */
    public function rmaReplacementCreated(int $rmaId): void
    {
        $order = $this->rmaOrder($rmaId);
        if ($order !== null) {
            $this->dispatcher->send(new RmaReplacementCreated($rmaId), $this->recipients->orderCustomer($order));
        }
    }

    private function rmaOrder(int $rmaId): ?Order
    {
        $rma = Rma::query()->find($rmaId, ['id', 'order_id']);

        return $rma === null ? null : Order::query()->find($rma->order_id, ['id', 'user_id', 'company_id', 'guest_email']);
    }

    /** 05.12 §5.1 `refund.failed`: accounts repay by bank transfer (05.4 §13.6). */
    public function refundFailed(int $refundPaymentId): void
    {
        $this->dispatcher->send(new RefundFailed($refundPaymentId), $this->recipients->role('accounts'));
    }

    /** 05.12 §5.1 `shipment.dispatched`: every shipment, full or partial (05.5 §7.2). */
    public function shipmentDispatched(int $shipmentId): void
    {
        $shipment = Shipment::query()->find($shipmentId, ['id', 'order_id']);
        $order = $shipment === null ? null : Order::query()->find($shipment->order_id, ['id', 'user_id', 'company_id', 'guest_email']);
        if ($shipment !== null && $order !== null) {
            $this->dispatcher->send(new ShipmentDispatched($shipmentId), $this->recipients->orderCustomer($order));
        }
    }

    public function invoiceIssued(int $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice !== null) {
            $this->dispatcher->send(new InvoiceIssued($invoiceId), $this->recipients->invoiceRecipients($invoice));
        }
    }

    /**
     * 05.12 §5.1.1: a card order's receipt or invoice is its payment
     * acknowledgement, so card orders get no `payment.received`.
     */
    public function paymentApplied(int $invoiceId, int $paymentId, int $amountMinor, ?string $orderPaymentMethod): void
    {
        if ($orderPaymentMethod === PaymentMethod::Card->value) {
            return;
        }

        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice !== null) {
            $this->dispatcher->send(new PaymentReceived($invoiceId, $paymentId, $amountMinor), $this->recipients->invoiceRecipients($invoice));
        }
    }

    public function invoiceDueSoon(Invoice $invoice): void
    {
        $this->dispatcher->send(new InvoiceDueSoon($invoice->id), $this->recipients->invoiceRecipients($invoice));
    }

    public function invoiceOverdue(Invoice $invoice): void
    {
        $recipients = $this->recipients->invoiceRecipients($invoice);
        if ($invoice->company_id !== null) {
            $recipients = [
                ...$recipients,
                ...$this->recipients->role('accounts'),
                ...$this->recipients->assignedRep($invoice->company_id),
            ];
        }

        $this->dispatcher->send(new InvoiceOverdue($invoice->id), $recipients);
    }

    /** 05.2 §11: an on-account checkout refused for credit, to the accounts role. */
    public function creditLimitReached(int $companyId, int $attemptedMinor): void
    {
        $this->dispatcher->send(
            new CreditLimitReached($companyId, $attemptedMinor, DisplayTime::local(now())->toDateString()),
            $this->recipients->role('accounts'),
        );
    }

    /**
     * 05.12 §5.1.2 — call with credit usage (used + held) before and after
     * a write, from inside the transaction that made it. Warns the owners
     * when the write crossed 80% of the limit upward; `$cause` names that
     * write (e.g. `order:123`), making the warning once per breach.
     */
    public function creditUsageChanged(int $companyId, int $limitMinor, int $usageBefore, int $usageAfter, string $cause): void
    {
        if ($limitMinor <= 0) {
            return;
        }

        $threshold = $limitMinor * self::CREDIT_WARNING_PERCENT;
        $crossed = $usageBefore * 100 < $threshold && $usageAfter * 100 >= $threshold;

        if ($crossed) {
            $this->dispatcher->send(new CreditLimitWarning($companyId, $cause), $this->recipients->companyOwners($companyId));
        }
    }
}
