<?php

namespace App\Console\Commands;

use App\Domain\Collection\CollectionExpiry;
use Illuminate\Console\Command;

/** 05.6 §7A.5 — every 5 minutes, one worker, no overlap (routes/console.php). */
final class ExpireUnpaidCollections extends Command
{
    protected $signature = 'collection:expire-unpaid';

    protected $description = 'Cancel unpaid pay-at-collection orders past their payment deadline and release their stock';

    public function handle(CollectionExpiry $expiry): int
    {
        $this->info('Expired '.$expiry->sweep().' unpaid collection orders.');

        return self::SUCCESS;
    }
}
