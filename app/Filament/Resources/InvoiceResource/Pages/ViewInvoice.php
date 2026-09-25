<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => $this->getInvoice()->archivedPdf !== null)
                ->authorize(fn () => auth()->user()?->can('view', $this->getInvoice()) ?? false)
                ->action(fn () => InvoiceResource::downloadPdf($this->getInvoice())),
        ];
    }

    private function getInvoice(): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return $invoice;
    }
}
