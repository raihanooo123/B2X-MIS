<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Support\StatusTabs;
use App\Models\PurchaseOrder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Raise purchase order')->icon('heroicon-m-plus')];
    }

    public function getTabs(): array
    {
        return StatusTabs::groups(PurchaseOrder::class, [
            'draft' => ['Draft', ['draft'], 'gray'],
            'open' => ['Open', ['sent', ...PurchaseOrder::RECEIVABLE_STATUSES], 'info'],
            'received' => ['Received', ['received', 'closed'], 'success'],
            'cancelled' => ['Cancelled', ['cancelled'], 'danger'],
        ]);
    }
}
