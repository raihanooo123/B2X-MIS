<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Domain\Purchasing\PurchaseOrderService;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PurchaseOrder $order */
        $order = $this->record;
        $data['lines'] = $order->lines()->orderBy('line_no')->get()->map(fn ($line) => [
            'sku_id' => $line->sku_id,
            'pack_id' => $line->pack_id,
            'pack_qty' => $line->pack_qty,
            'unit_fob' => PurchaseOrderResource::unitCostInput($line),
        ])->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PurchaseOrder $record */
        return app(PurchaseOrderService::class)->updateDraft($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
