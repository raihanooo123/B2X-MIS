<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Domain\Catalogue\CategoryReparenter;
use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * `path`/`depth`/`category_closure` are computed, not user-entered
     * (02 §5.3) — set once the category has a real id to build a path
     * segment from.
     */
    protected function afterCreate(): void
    {
        if ($this->record instanceof Category) {
            (new CategoryReparenter)->apply($this->record);
        }
    }
}
