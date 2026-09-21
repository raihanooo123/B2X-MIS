<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ListRecords;

class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ViewAction::make(),
            EditAction::make(),
        ];
    }
}
