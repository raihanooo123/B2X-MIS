<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Domain\Ordering\GuestOrderLink;
use App\Domain\Returns\FaultReports;
use App\Domain\Storefront\Branding;
use App\Domain\Storefront\PreContractInformation;
use App\Models\Order;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Models\Sku;

/**
 * 05.12 §5.1 `rma.approved` — 05.4 §7.2, §13.3. For a consumer's
 * cancellation: what to send back, where, by when, with the RMA number on
 * the parcel, and who pays the return postage (05.15 §7.2) — for a pallet,
 * the estimated cost they were told before ordering, or our collection
 * (02 §27).
 */
final class RmaApproved extends Notice
{
    use FormatsForMail;

    public function __construct(public readonly int $rmaId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::RmaApproved;
    }

    public function subject(): array
    {
        return ['rma', $this->rmaId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $rma = Rma::query()->with(['lines' => fn ($q) => $q->orderBy('line_no')])->findOrFail($this->rmaId);
        $order = Order::query()->findOrFail($rma->order_id);
        $seller = Branding::current()->seller;
        $address = $seller->addressLines === [] ? 'the address on our website' : implode(', ', $seller->addressLines);
        $hygiene = Sku::query()->whereIn('id', $rma->lines->pluck('sku_id'))->where('non_refundable_reason', 'hygiene')->exists();

        $fault = $rma->return_reason !== 'consumer_cancellation';
        $paragraphs = [$fault
            ? "We have accepted your report about these items from order {$order->order_number}:"
            : "We have received your cancellation of these items from order {$order->order_number}:"];
        foreach ($rma->lines as $line) {
            /** @var RmaLine $line */
            $paragraphs[] = "• {$line->requested_pack_qty} × {$line->sku_code_snapshot} {$line->name_snapshot}";
        }

        if ($fault) {
            $paragraphs[] = 'We will contact you to collect them, at our cost.';
            $paragraphs[] = FaultReports::withinRejectPeriod($rma)
                ? 'Once we have them back, we refund you in full, including the delivery charge if you return the whole order.'
                : 'Once we have checked them, we will repair or replace them, or refund you.';

            return new MailContent(
                subject: "Return {$rma->rma_number} for order {$order->order_number}",
                heading: 'We have accepted your report',
                paragraphs: $paragraphs,
                facts: [['label' => 'Return number', 'value' => $rma->rma_number], ['label' => 'Order', 'value' => $order->order_number]],
                actionLabel: 'View your order',
                actionUrl: GuestOrderLink::customerUrl($order),
            );
        }

        if ($rma->return_method === 'collection') {
            $paragraphs[] = 'These goods are delivered on a pallet. We will contact you to collect them, at our cost.';
        } else {
            $paragraphs[] = 'Please send them back by '.self::date($rma->return_by_date).", with {$rma->rma_number} written clearly on the parcel, to: {$address}.";
            $paragraphs[] = $order->delivery_method === 'pallet'
                ? PreContractInformation::palletReturnStatement($order->return_cost_estimate_gross_minor)
                : 'You pay the cost of posting them back, as you cancelled because you changed your mind.';
        }
        if ($hygiene) {
            $paragraphs[] = 'Items sealed for health or hygiene reasons can only be returned with the seal unbroken.';
        }
        $paragraphs[] = 'We refund you within 14 days of receiving the goods, or of you showing us proof that you sent them back, whichever is earlier. We refund the way you paid.';

        return new MailContent(
            subject: "Cancellation {$rma->rma_number} for order {$order->order_number}",
            heading: 'Your cancellation is confirmed',
            paragraphs: $paragraphs,
            facts: array_values(array_filter([
                ['label' => 'Return number', 'value' => $rma->rma_number],
                $rma->return_method === 'collection' ? null : ['label' => 'Send back by', 'value' => self::date($rma->return_by_date)],
                ['label' => 'Order', 'value' => $order->order_number],
            ])),
            actionLabel: 'View your order',
            actionUrl: GuestOrderLink::customerUrl($order),
        );
    }
}
