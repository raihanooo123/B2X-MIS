<?php

namespace App\Console\Commands;

use App\Domain\Credit\CreditNotices;
use Illuminate\Console\Command;

/** 05.2 §10 — daily while a request is pending; one per request per UK day (05.12 §8.2). */
final class SendApprovalReminders extends Command
{
    protected $signature = 'credit:approval-reminders';

    protected $description = 'Remind approvers (and accounts, for credit shortfalls) of trade orders still awaiting approval';

    public function handle(CreditNotices $notices): int
    {
        $this->info('Reminded about '.$notices->reminders().' pending approvals.');

        return self::SUCCESS;
    }
}
