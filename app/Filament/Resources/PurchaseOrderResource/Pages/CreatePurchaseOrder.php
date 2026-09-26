<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Domain\Purchasing\PurchaseOrderService;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(PurchaseOrderService::class)->createDraft($data, (int) auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        /** @var PurchaseOrder $record */
        $record = $this->record;

        return PurchaseOrderResource::getUrl('view', ['record' => $record]);
    }
}
