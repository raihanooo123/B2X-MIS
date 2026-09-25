<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Notices\CreditLimitReached;
use App\Domain\Notifications\Notices\CreditLimitWarning;
use App\Domain\Notifications\Notices\InvoiceDueSoon;
use App\Domain\Notifications\Notices\InvoiceIssued;
use App\Domain\Notifications\Notices\InvoiceOverdue;
use App\Domain\Notifications\Notices\OrderConfirmed;
use App\Domain\Notifications\Notices\PaymentReceived;
use App\Domain\Ordering\PaymentMethod;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;

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

    public function orderConfirmed(int $orderId): void
    {
        $order = Order::query()->find($orderId, ['id', 'user_id', 'company_id']);
        if ($order !== null) {
            $this->dispatcher->send(new OrderConfirmed($orderId), $this->recipients->orderCustomer($order));
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
            new CreditLimitReached($companyId, $attemptedMinor, now()->timezone('Europe/London')->toDateString()),
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
