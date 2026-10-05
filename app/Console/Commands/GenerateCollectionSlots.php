<?php

namespace App\Console\Commands;

use App\Domain\Collection\CollectionSlots;
use Illuminate\Console\Command;

/** 05.6 §7A.1 — daily; idempotent on `collection_slots_slot_uq` (routes/console.php). */
final class GenerateCollectionSlots extends Command
{
    protected $signature = 'collection:generate-slots';

    protected $description = 'Generate future collection slots from each location pattern';

    public function handle(CollectionSlots $slots): int
    {
        $this->info('Created '.$slots->generate().' collection slots.');

        return self::SUCCESS;
    }
}
