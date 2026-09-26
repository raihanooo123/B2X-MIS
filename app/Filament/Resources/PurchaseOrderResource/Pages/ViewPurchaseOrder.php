<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Domain\Purchasing\PurchaseOrderService;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => $this->purchaseOrder()->status === 'draft'),
            Action::make('confirm')
                ->label('Confirm supplier accepted')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('The supplier has accepted this order outside B2X. Confirmation makes its quantities incoming and enables Goods in.')
                ->visible(fn (): bool => Gate::allows('confirm', $this->purchaseOrder()))
                ->action(function (): void {
                    $order = $this->purchaseOrder();
                    Gate::authorize('confirm', $order);
                    app(PurchaseOrderService::class)->confirm($order);
                    $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $order]));
                }),
            Action::make('cancel')
                ->label('Cancel PO')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Close any open Goods-in receipt first. Cancellation removes outstanding incoming quantity; received stock is not reversed.')
                ->visible(fn (): bool => Gate::allows('cancel', $this->purchaseOrder()))
                ->action(function (): void {
                    $order = $this->purchaseOrder();
                    Gate::authorize('cancel', $order);
                    app(PurchaseOrderService::class)->cancel($order);
                    $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $order]));
                }),
        ];
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $record = $this->getRecord();
        if (! $record instanceof PurchaseOrder) {
            throw new \LogicException('This page requires a purchase order.');
        }

        return $record;
    }
}
