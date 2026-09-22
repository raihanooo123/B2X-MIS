<?php

namespace App\Filament\Resources\SkuResource\Pages;

use App\Filament\Resources\SkuResource;
use Filament\Resources\Pages\EditRecord;

class EditSku extends EditRecord
{
    protected static string $resource = SkuResource::class;

    /**
     * No delete action — nobody gets delete (SkuPolicy denies it
     * unconditionally); archiving via `status` comes later.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
