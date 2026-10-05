<?php

namespace App\Console\Commands;

use App\Domain\Credit\CreditReconciliation;
use Illuminate\Console\Command;

/** 05.2 §18.1 — hourly; reports drift, never repairs it. */
final class ReconcileCreditProjections extends Command
{
    protected $signature = 'credit:reconcile';

    protected $description = 'Rebuild companies’ held/used credit and account balance from their sources and flag any drift';

    public function handle(CreditReconciliation $reconciliation): int
    {
        $drift = $reconciliation->drift();
        foreach ($drift as $d) {
            $this->error("Company {$d['company_id']}: {$d['field']} is {$d['stored']}, rebuilt {$d['rebuilt']}.");
        }
        $this->info(count($drift) === 0 ? 'No credit drift.' : count($drift).' credit projection(s) drifted.');

        return $drift === [] ? self::SUCCESS : self::FAILURE;
    }
}
