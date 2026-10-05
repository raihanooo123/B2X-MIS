<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\OrderApprovalRequest;

/**
 * 05.12 §5 `order.approval_granted` / `order.approval_rejected` (05.2
 * §10): to the buyer who placed the order, with the reason on a
 * rejection. An expired request is told through `order.cancelled`.
 */
final class OrderApprovalDecided extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $requestId, public readonly bool $approved) {}

    public function key(): NotificationKey
    {
        return $this->approved ? NotificationKey::OrderApprovalGranted : NotificationKey::OrderApprovalRejected;
    }

    public function subject(): array
    {
        return ['order_approval_request', $this->requestId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $request = OrderApprovalRequest::query()->with('order')->findOrFail($this->requestId);
        $number = $request->order->order_number ?? '';
        $approved = $this->approved;
        $nextStep = match (true) {
            ! $approved => 'The order has been cancelled and nothing will be charged.',
            $request->order?->status === 'pending_payment' => 'Pay for the order within 2 hours to confirm it.',
            $request->order?->status === 'awaiting_approval' => 'It is still waiting for another approval before it is confirmed.',
            default => 'The order is confirmed.',
        };

        return new MailContent(
            subject: "Order {$number} ".($approved ? 'approved' : 'not approved'),
            heading: $approved ? 'Your order was approved' : 'Your order was not approved',
            paragraphs: $approved || $request->decision_reason === null ? [$nextStep] : [$nextStep, "Reason: {$request->decision_reason}"],
            facts: [
                ['label' => 'Order', 'value' => $number],
                ['label' => 'Order total (inc VAT)', 'value' => self::money($request->order_gross_minor)],
            ],
            actionLabel: 'View the order',
            actionUrl: $request->order === null ? null : url('/orders/'.$request->order->public_id.'/confirmation'),
        );
    }
}
