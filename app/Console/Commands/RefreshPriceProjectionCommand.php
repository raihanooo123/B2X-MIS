<?php

namespace App\Console\Commands;

use App\Domain\Storefront\ProductPriceProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 02 §29.4 — the storefront price sort key. `--stale` (every 5 minutes)
 * refreshes rows whose `stale_after` has passed: a scheduled base list
 * starting or ending, a dated VAT change. Without it (nightly, and once
 * after the table is created) every product is recomputed and any drift
 * from the stored rows is logged and corrected — a sort order has no money
 * consequence, and the price lists themselves are never touched.
 */
class RefreshPriceProjectionCommand extends Command
{
    protected $signature = 'storefront:refresh-price-projection {--stale : only rows past their stale_after}';

    protected $description = 'Refresh the storefront price sort key (02 §29).';

    public function handle(ProductPriceProjector $projector): int
    {
        if ($this->option('stale')) {
            $this->info($projector->refreshStale().' stale row(s) refreshed.');

            return self::SUCCESS;
        }

        $drift = $projector->refreshAll();
        if ($drift > 0) {
            Log::warning('Storefront price projection drift corrected by the nightly rebuild (02 §29.4).', ['rows' => $drift]);
        }
        $this->info("Rebuilt; {$drift} row(s) had drifted and were corrected.");

        return self::SUCCESS;
    }
}
