<?php

namespace App\Filament\Resources\SkuResource\Pages;

use App\Filament\Resources\SkuResource;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ListRecords;

class ListSkus extends ListRecords
{
    protected static string $resource = SkuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ViewAction::make(),
            EditAction::make(),
        ];
    }
}
