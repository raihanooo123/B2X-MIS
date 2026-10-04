<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\Order;
use App\Models\Rma;
use Illuminate\Support\Str;

/**
 * 05.12 §5.1 `rma.proof_rejected` — 05.4 §13.5. Staff could not accept the
 * customer's proof of sending; they may upload again. Each rejection is
 * its own message.
 */
final class RmaProofRejected extends Notice
{
    use FormatsForMail;

    public readonly string $requestId;

    public function __construct(public readonly int $rmaId, public readonly string $reason)
    {
        $this->requestId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::RmaProofRejected;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function occurrence(): string
    {
        return $this->requestId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->findOrFail($this->rmaId);
        $order = Order::query()->findOrFail($rma->order_id);

        return new MailContent(
            subject: "We could not accept your proof of sending for {$rma->rma_number}",
            heading: 'Please send your proof of sending again',
            paragraphs: [
                "We could not accept the proof of sending you gave us for return {$rma->rma_number} (order {$order->order_number}).",
                "Reason: {$this->reason}",
                'Please upload a clear photo or PDF of your proof of postage or tracking receipt on your order page. If you have not sent the items yet, please send them by '.self::date($rma->return_by_date).'.',
            ],
            actionLabel: 'Upload proof of sending',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
