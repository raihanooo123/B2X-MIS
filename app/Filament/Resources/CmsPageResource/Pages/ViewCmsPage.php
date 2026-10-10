<?php

namespace App\Filament\Resources\CmsPageResource\Pages;

use App\Filament\Resources\CmsPageResource;
use App\Models\CmsPage;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** The page's draft, what is live, and every published version below. */
class ViewCmsPage extends ViewRecord
{
    protected static string $resource = CmsPageResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof CmsPage ? $record->key()->label() : 'Page';
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Edit draft')->icon('heroicon-m-pencil-square')];
    }
}
