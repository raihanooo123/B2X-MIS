<?php

namespace App\Filament\Resources\TermsVersionResource\Pages;

use App\Filament\Resources\TermsVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTermsVersions extends ListRecords
{
    protected static string $resource = TermsVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Publish a new version')];
    }
}
