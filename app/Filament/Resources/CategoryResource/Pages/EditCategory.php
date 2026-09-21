<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Domain\Catalogue\CategoryReparenter;
use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
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
