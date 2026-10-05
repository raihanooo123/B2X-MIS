<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('credit')->label('Credit')->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => Gate::allows('manageAnyCredit', Company::class))
                ->url(fn (): string => CompanyResource::getUrl('credit', ['record' => $this->getRecord()])),
        ];
    }
}
