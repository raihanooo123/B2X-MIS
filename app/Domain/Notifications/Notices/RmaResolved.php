<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rma;

/**
 * 05.12 §5.1 `rma.resolved` — 05.4 §7.5, §13.6. The return is settled: the
 * refund and how it is paid, or the repair or replacement. The credit note
 * PDF is attached once the PDF renderer exists (05.12 §12.2).
 */
final class RmaResolved extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaResolved;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->findOrFail($this->rmaId);
        $order = Order::query()->findOrFail($rma->order_id);
        $refund = $rma->refund_payment_id === null ? null : Payment::query()->find($rma->refund_payment_id);
        $credit = $rma->credit_note_id === null ? null : CreditNote::query()->find($rma->credit_note_id);

        $paragraphs = match ($rma->resolution_type) {
            'repair' => ["We will repair the items from return {$rma->rma_number} and send them back to you, at our cost."],
            'replacement' => ["We will send you replacements for the items from return {$rma->rma_number}, at our cost."],
            default => array_values(array_filter([
                "Return {$rma->rma_number} for order {$order->order_number} is complete.",
                $rma->refund_gross_minor > 0 && $refund?->gateway === 'stripe' ? 'We are refunding '.self::money($rma->refund_gross_minor).' to the card you paid with. Your bank may take a few days to show it.' : null,
                $rma->refund_gross_minor > 0 && $refund?->gateway === 'bacs' ? 'We are refunding '.self::money($rma->refund_gross_minor).' by bank transfer and will contact you for your bank details.' : null,
                $rma->refund_gross_minor === 0 ? 'No refund is due for the items we received.' : null,
            ])),
        };

        $facts = [['label' => 'Return', 'value' => $rma->rma_number]];
        if ($rma->resolution_type === 'credit_note') {
            $facts[] = ['label' => 'Goods refunded (net)', 'value' => self::money($rma->refund_net_minor)];
            if ($rma->delivery_refund_net_minor > 0) {
                $facts[] = ['label' => 'Delivery refunded (net)', 'value' => self::money($rma->delivery_refund_net_minor)];
            }
            $facts[] = ['label' => 'VAT', 'value' => self::money($rma->refund_tax_minor + $rma->delivery_refund_tax_minor)];
            $facts[] = ['label' => 'Total refund', 'value' => self::money($rma->refund_gross_minor)];
            if ($credit !== null) {
                $facts[] = ['label' => 'Credit note', 'value' => $credit->credit_note_number];
            }
        }

        return new MailContent(
            subject: "Return {$rma->rma_number} complete",
            heading: $rma->resolution_type === 'credit_note' ? 'Your refund' : 'Your return is complete',
            paragraphs: $paragraphs,
            facts: $facts,
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
