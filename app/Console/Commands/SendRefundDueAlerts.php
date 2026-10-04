<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Notifications;
use App\Models\Rma;
use App\Support\DisplayTime;
use Illuminate\Console\Command;

/**
 * 05.4 §13.5 — accounts are alerted when a consumer refund falls due within
 * 3 days (UK dates) and has not been made. Once per deadline: the dispatcher's
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

        Rma::query()
            ->whereNotNull('refund_due_on')
            ->where('refund_due_on', '<=', $horizon)
            ->whereIn('status', ['approved', 'awaiting_goods', 'received', 'inspected'])
            ->whereNull('refund_payment_id')
            ->orderBy('id')
            ->each(function (Rma $rma) use ($notifications, &$count) {
                $notifications->rmaRefundDueSoon($rma->id, (string) $rma->refund_due_on?->toDateString());
                $count++;
            });

        $this->info("Checked {$count} refund(s) due by {$horizon}; alerts already sent are skipped.");

        return self::SUCCESS;
    }
}
