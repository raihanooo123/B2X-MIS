<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * No delete action — nobody gets delete (ProductPolicy denies it
     * unconditionally); archiving via `status` comes later.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
