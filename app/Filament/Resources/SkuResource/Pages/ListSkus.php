<?php

namespace App\Filament\Resources\SkuResource\Pages;

use App\Filament\Resources\SkuResource;
use App\Filament\Support\CatalogueStatus;
use App\Filament\Support\StatusTabs;
use App\Models\Sku;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSkus extends ListRecords
{
    protected static string $resource = SkuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->icon('heroicon-m-plus'),
        ];
    }

    public function getTabs(): array
    {
        return StatusTabs::for(Sku::class, CatalogueStatus::LIFECYCLE);
    }
}
