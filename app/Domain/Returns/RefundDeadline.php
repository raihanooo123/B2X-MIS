<?php

namespace App\Domain\Returns;

use App\Models\Rma;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;

/**
 * 05.4 §13.5 — the statutory refund deadline of a consumer return (CCR
 * reg. 34(4)–(6)):
 *
 *   - faulty goods: 14 days after accounts approval (CRA s.20(15));
 *   - a cancellation where we collect the goods: 14 days after the customer told us;
 *   - otherwise: 14 days after the EARLIER of the goods arriving
 *     (`received_at`) and the customer's proof of sending (`goods_sent_at`,
 *     the upload time) — UK dates;
 *   - neither yet: no deadline; the refund may be withheld (reg. 34(5)).
 *
 * With proof of sending, the deadline stands even if the parcel never
 * arrives. Recomputed by every write that changes one of its inputs.
 */
final class RefundDeadline
{
    public const DAYS = 14;

    public static function for(Rma $rma): ?CarbonImmutable
    {
        if ($rma->company_id !== null) {
            return null;
        }

        if ($rma->return_reason !== 'consumer_cancellation') {
            return $rma->approved_at === null ? null : self::day($rma->approved_at)->addDays(self::DAYS);
        }

        if ($rma->return_method === 'collection') {
            return $rma->cancellation_notified_at === null ? null : self::day($rma->cancellation_notified_at)->addDays(self::DAYS);
        }

        $starts = array_filter([$rma->received_at, $rma->goods_sent_at]);
        if ($starts === []) {
            return null;
        }

        $earliest = min(array_map(fn ($at) => self::day($at), $starts));

        return $earliest->addDays(self::DAYS);
    }

    /** Sets `refund_due_on` from the RMA's current inputs (unsaved). */
    public static function apply(Rma $rma): void
    {
        $rma->setAttribute('refund_due_on', self::for($rma)?->toDateString());
    }

    private static function day(\DateTimeInterface $at): CarbonImmutable
    {
        return DisplayTime::local($at)->startOfDay();
    }
}
