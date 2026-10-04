<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Notifications;
use App\Models\Payment;
use App\Models\Rma;
use App\Support\DisplayTime;
use Illuminate\Console\Command;

/**
 * 05.4 §13.5 — accounts are alerted when a consumer refund falls due within
 * 3 days (UK dates) and has not been made — before settlement, or after it
 * while the refund is unpaid (a refused card refund, a BACS refund not yet
 * recorded, 05.4 §13.6). Once per deadline: the dispatcher's
 * dedup key includes the due date, so re-running is harmless and a
 * recomputed, earlier deadline alerts again. Served by `rmas_refund_due_idx`.
 */
class SendRefundDueAlerts extends Command
{
    protected $signature = 'returns:refund-due-alerts';

    protected $description = 'Alert accounts to consumer refunds due within 3 days (05.4 §13.5)';

    public const DAYS_BEFORE = 3;

    public function handle(Notifications $notifications): int
    {
        $horizon = DisplayTime::local(now())->startOfDay()->addDays(self::DAYS_BEFORE)->toDateString();
        $count = 0;

        $unpaid = fn () => Payment::query()->select('id')->where('type', 'refund')->where('status', '<>', 'captured');

        Rma::query()
            ->whereNotNull('refund_due_on')
            ->where('refund_due_on', '<=', $horizon)
            ->where(fn ($q) => $q
                // Not settled yet, or settled with nothing paid.
                ->where(fn ($q) => $q->whereIn('status', ['approved', 'awaiting_goods', 'received', 'inspected'])
                    ->where(fn ($q) => $q->whereNull('refund_payment_id')->orWhereIn('refund_payment_id', $unpaid())))
                // Settled, but the refund is still unpaid: a refused card refund, or a BACS refund not yet recorded.
                ->orWhere(fn ($q) => $q->whereIn('status', ['resolved', 'partially_resolved'])->whereIn('refund_payment_id', $unpaid())))
            ->orderBy('id')
            ->each(function (Rma $rma) use ($notifications, &$count) {
                $notifications->rmaRefundDueSoon($rma->id, (string) $rma->refund_due_on?->toDateString());
                $count++;
            });

        $this->info("Checked {$count} refund(s) due by {$horizon}; alerts already sent are skipped.");

        return self::SUCCESS;
    }
}
