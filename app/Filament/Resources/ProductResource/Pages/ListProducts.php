<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Support\CatalogueStatus;
use App\Filament\Support\StatusTabs;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->icon('heroicon-m-plus'),
        ];
    }

    public function getTabs(): array
    {
        return StatusTabs::for(Product::class, CatalogueStatus::LIFECYCLE);
    }
}
