<?php

namespace App\Console\Commands;

use App\Domain\Credit\CreditExpiry;
use Illuminate\Console\Command;

/** 05.2 §18.2 — every minute, one worker, no overlap (routes/console.php). */
final class ExpireTradeApprovals extends Command
{
    protected $signature = 'credit:expire-approvals';

    protected $description = 'Cancel trade orders whose approval expired (48 h) or whose payment after approval is overdue (2 h), releasing what they hold';

    public function handle(CreditExpiry $expiry): int
    {
        $this->info('Cancelled '.$expiry->sweep().' unapproved or unpaid trade orders.');

        return self::SUCCESS;
    }
}
