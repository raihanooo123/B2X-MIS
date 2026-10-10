<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\StatusTabs;
use App\Models\Invoice;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getSubheading(): string
    {
        return 'Invoices for trade accounts and receipts for card and cash orders. Issued automatically and never edited; a correction is a credit note.';
    }

    public function getTabs(): array
    {
        return StatusTabs::groups(Invoice::class, [
            'unpaid' => ['Unpaid', ['issued', 'part_paid', 'overdue'], 'warning'],
            'overdue' => ['Overdue', ['overdue'], 'danger'],
            'paid' => ['Paid', ['paid'], 'success'],
            'closed' => ['Credited or void', ['credited', 'void'], 'gray'],
        ]);
    }
}
