<?php

namespace App\Console\Commands;

use App\Domain\Collection\CollectionSlots;
use Illuminate\Console\Command;

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
