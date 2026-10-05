<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Credit\ApprovalKind;
use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\OrderApprovalRequest;
use App\Support\DisplayTime;

/**
 * 05.12 §5 `order.awaiting_approval` and its daily `_reminder` (05.2 §10,
 * §18): a buyer-limit request to the company's other owners and
 * approvers; a credit shortfall to accounts. The reminder's occurrence is
 * the UK date, so at most one a day.
 */
final class OrderAwaitingApproval extends Notice
{
    use FormatsForMail;

    public function __construct(
        public readonly int $requestId,
        public readonly bool $reminder = false,
        public readonly string $day = '',
    ) {}

    public function key(): NotificationKey
    {
        return $this->reminder ? NotificationKey::OrderAwaitingApprovalReminder : NotificationKey::OrderAwaitingApproval;
    }

    public function subject(): array
    {
        return ['order_approval_request', $this->requestId];
    }

    public function occurrence(): string
    {
        return $this->reminder ? $this->day : '';
    }

    public function content(Recipient $recipient): MailContent
    {
        $request = OrderApprovalRequest::query()->with(['order', 'company', 'buyer'])->findOrFail($this->requestId);
        $credit = $request->approval_kind === ApprovalKind::CreditException->value;
        $number = $request->order->order_number ?? '';
        $buyer = trim(($request->buyer->first_name ?? '').' '.($request->buyer->last_name ?? ''));
        $account = $request->company->name ?? '';

        return new MailContent(
            subject: ($this->reminder ? 'Reminder: ' : '')."Order {$number} needs ".($credit ? 'a credit decision' : 'your approval'),
            heading: $credit ? 'An order is over the available credit' : 'An order is waiting for your approval',
            paragraphs: $credit
                ? ["{$account}'s order is more than its available credit. Stock is not reserved until the credit limit is raised or the buyer chooses to pay in advance."]
                : ["{$buyer} placed an order above their approval limit. Stock and credit are held until it is approved, rejected or expires."],
            facts: [
                ['label' => 'Order', 'value' => $number],
                ['label' => 'Account', 'value' => $account],
                ['label' => 'Order total (inc VAT)', 'value' => self::money($request->order_gross_minor)],
                ['label' => 'Expires (UK time)', 'value' => DisplayTime::format($request->expires_at, 'd/m/Y H:i')],
            ],
            actionLabel: 'Review the order',
            actionUrl: $credit ? url('/admin/credit-exceptions') : url('/trade/approvals/'.$request->public_id),
        );
    }
}
