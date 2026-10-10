<?php

namespace App\Filament\Resources\StocktakeResource\Pages;

use App\Filament\Resources\StocktakeResource;
use App\Filament\Support\StatusTabs;
use App\Models\Stocktake;
use Filament\Resources\Pages\ListRecords;

class ListStocktakes extends ListRecords
{
    protected static string $resource = StocktakeResource::class;

    public function getSubheading(): string
    {
        return 'Every stock count, with what was expected and any difference. Counts are done on the warehouse Stocktake screen.';
    }

    public function getTabs(): array
    {
        $groups = [];
        foreach (StocktakeResource::STATUSES as $status => $label) {
            $groups[$status] = [$label, [$status], StocktakeResource::statusColor($status)];
        }

        return StatusTabs::groups(Stocktake::class, $groups);
    }
}
