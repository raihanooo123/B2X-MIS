<?php

namespace App\Domain\Returns;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RmaLine;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 05.4 §13.3 — what a consumer may cancel on a dispatched order, line by
 * line, at a given moment. Replaces 05.4 §6.1 rules 1, 3 and 4 for consumer
 * orders; rules 2 (quantity already returned) and 5 (recall) stand.
 *
 *   - window: until the end of possession day + 14 (UK); a recalled batch
 *     on the line overrides it (rule 5);
 *   - statutory exclusions only: `bespoke` cannot be cancelled (reg.
 *     28(1)(b)); `hygiene` only with its seal unbroken (reg. 28(1)(e)), a
 *     notice, not a refusal; `consumable` and `electrical_sealed` are trade
 *     exclusions and do not apply (05.15 §12 Q5);
 *   - quantity: dispatched, less what open or settled returns already hold
 *     (rejected, cancelled and not-received returns hold nothing).
 *
 * Read-only. ConsumerCancellations re-runs it under the order's row lock.
 */
final readonly class CancellationEligibility
{
    /** Returns that no longer hold their quantity. */
    private const RELEASED_STATUSES = ['rejected', 'cancelled', 'not_received'];

    /**
     * @param  array<int, array{order_line: OrderLine, returnable_pack_qty: int, eligible: bool, refusal: string|null, notice: string|null}>  $lines  keyed by line_no
     */
    public function __construct(
        public bool $available,
        public ?string $reason,
        public ?string $message,
        public ?PossessionDay $possession,
        public array $lines,
    ) {}

    public static function for(Order $order, CarbonImmutable $at): self
    {
        if ($order->company_id !== null) {
            return self::unavailable('not_consumer_order', 'Trade orders are returned under your account terms. Please contact us.');
        }
        if (in_array($order->status, ['confirmed', 'picking'], true)) {
            return self::unavailable('not_dispatched', 'This order has not been sent yet, so you can cancel the whole order instead.');
        }
        if ($order->status === 'part_dispatched') {
            // 05.15 §12 Q7: staff handle part-dispatched orders at launch.
            return self::unavailable('part_dispatched', 'Part of your order is still to be sent. Please contact us and we will cancel it for you.');
        }

        $possession = PossessionDay::for($order);
        if ($possession === null) {
            return self::unavailable('not_started', 'You can cancel once your order has been sent.');
        }

        $windowOpen = $possession->isOpen($at);
        $lines = [];
        $anyEligible = false;

        foreach (self::orderLines($order) as $line) {
            $sku = Sku::query()->find($line->sku_id, ['id', 'non_refundable_reason']);
            $reason = $sku?->getAttribute('non_refundable_reason');
            $recalled = self::recalled($line->id);
            $returnable = max(0, $line->dispatched_base_qty - self::heldBaseQty($line->id));
            $packs = intdiv($returnable, max(1, $line->pack_base_units));

            $refusal = match (true) {
                $packs === 0 => 'Already returned or not sent.',
                $reason === 'bespoke' && ! $recalled => 'Made to your specification, so it cannot be cancelled.',
                ! $windowOpen && ! $recalled => 'The 14-day cancellation period ended on '.$possession->lastDay()->format('j F Y').'.',
                default => null,
            };

            $lines[$line->line_no] = [
                'order_line' => $line,
                'returnable_pack_qty' => $refusal === null ? $packs : 0,
                'eligible' => $refusal === null,
                'refusal' => $refusal,
                'notice' => $reason === 'hygiene' ? 'Can only be returned with its seal unbroken.' : null,
            ];
            $anyEligible = $anyEligible || $refusal === null;
        }

        return new self(
            available: $anyEligible,
            reason: $anyEligible ? null : ($windowOpen ? 'nothing_returnable' : 'window_closed'),
            message: $anyEligible ? null : ($windowOpen
                ? 'There is nothing left on this order that can be cancelled.'
                : 'The 14-day cancellation period ended on '.$possession->lastDay()->format('j F Y').'.'),
            possession: $possession,
            lines: $lines,
        );
    }

    private static function unavailable(string $reason, string $message): self
    {
        return new self(false, $reason, $message, null, []);
    }

    /**
     * @return list<OrderLine>
     */
    private static function orderLines(Order $order): array
    {
        return array_values(OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get()->all());
    }

    /** What earlier returns of this line still hold (05.4 §6.1 rule 2). */
    private static function heldBaseQty(int $orderLineId): int
    {
        return (int) RmaLine::query()
            ->where('order_line_id', $orderLineId)
            ->whereHas('rma', fn ($q) => $q->whereNotIn('status', self::RELEASED_STATUSES))
            ->sum('requested_base_qty');
    }

    /** 05.4 §6.1 rule 5: a recalled batch dispatched on this line. */
    private static function recalled(int $orderLineId): bool
    {
        return DB::table('shipment_line_batches')
            ->join('shipment_lines', 'shipment_lines.id', '=', 'shipment_line_batches.shipment_line_id')
            ->join('batches', 'batches.id', '=', 'shipment_line_batches.batch_id')
            ->where('shipment_lines.order_line_id', $orderLineId)
            ->where('batches.status', 'recalled')
            ->exists();
    }
}
