<?php

namespace App\Filament\Resources\PayAtCollectionSuspensionResource\Pages;

use App\Filament\Resources\PayAtCollectionSuspensionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPayAtCollectionSuspension extends ViewRecord
{
    protected static string $resource = PayAtCollectionSuspensionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PayAtCollectionSuspensionResource::liftAction(Action::make('lift')),
        ];
    }
}
