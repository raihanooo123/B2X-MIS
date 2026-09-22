<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Domain\Catalogue\CategoryReparenter;
use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * No delete action — nobody gets delete (CategoryPolicy denies it
     * unconditionally); archiving via `status` comes later.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Only re-derive path/depth/closure when parent_id actually moved —
     * cheap on every other save, and CategoryReparenter's descendant
     * cascade is real (if bounded) work not worth doing when nothing
     * about this category's position changed.
     */
    protected function afterSave(): void
    {
        if ($this->record instanceof Category && $this->record->wasChanged('parent_id')) {
            (new CategoryReparenter)->apply($this->record);
        }
    }
}
