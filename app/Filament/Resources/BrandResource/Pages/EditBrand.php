<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    /**
     * No delete action — nobody gets delete (BrandPolicy denies it
     * unconditionally); archiving via `status` comes later.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
