<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use App\Filament\Support\PurchasingStatus;
use App\Filament\Support\StatusTabs;
use App\Models\Supplier;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->icon('heroicon-m-plus')];
    }

    public function getTabs(): array
    {
        $groups = [];
        foreach (PurchasingStatus::SUPPLIER as $status => $label) {
            $groups[$status] = [$label, [$status], PurchasingStatus::color($status)];
        }

        return StatusTabs::groups(Supplier::class, $groups);
    }
}
