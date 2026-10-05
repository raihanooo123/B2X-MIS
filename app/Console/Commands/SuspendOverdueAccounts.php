<?php

namespace App\Console\Commands;

use App\Domain\Credit\CreditControl;
use Illuminate\Console\Command;

/** 05.2 §9, §18.5 — nightly: suspend for debt beyond `credit.auto_suspend_days` (default 30). */
final class SuspendOverdueAccounts extends Command
{
    protected $signature = 'credit:suspend-overdue';

    protected $description = 'Suspend trade accounts with an invoice unpaid beyond the automatic-suspension threshold (prepayment still allowed)';

    public function handle(CreditControl $control): int
    {
        $this->info('Suspended '.$control->suspendOverdue().' trade accounts for overdue debt.');

        return self::SUCCESS;
    }
}
