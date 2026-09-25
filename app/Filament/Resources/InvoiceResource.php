<?php

namespace App\Filament\Resources;

use App\Domain\Billing\Documents\InvoiceDocumentBuilder;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Support\MoneyFormatter;
use App\Models\Invoice;
use App\Models\OrderLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Doc 02 §14.5.2, §21.2 — invoices and receipts, read-only. Documents are
 * issued by InvoiceService and never edited here; the archived PDF
 * (§21.1) is what the customer received and is downloadable once
 * rendered.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public const STATUSES = [
        'issued' => 'Issued',
        'part_paid' => 'Part paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'credited' => 'Credited',
        'void' => 'Void',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Document')
                ->schema([
                    TextEntry::make('invoice_number')->label('Number'),
                    TextEntry::make('kind')->state(fn (Invoice $record) => $record->isReceipt() ? 'Receipt' : 'VAT invoice'),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state),
                    TextEntry::make('order.order_number')->label('Order'),
                    TextEntry::make('issued_at')->label('Issued')->dateTime(),
                    TextEntry::make('payment_terms')->placeholder('—'),
                    TextEntry::make('due_at')->label('Due')->date()->placeholder('—'),
                    TextEntry::make('customer')->state(fn (Invoice $record) => self::customerName($record)),
                ])
                ->columns(4),

            Section::make('Lines')
                ->schema([
                    RepeatableEntry::make('orderLines')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('sku_code_snapshot')->label('SKU'),
                            TextEntry::make('name_snapshot')->label('Description')->columnSpan(2),
                            TextEntry::make('quantity')->state(fn (OrderLine $record) => "{$record->pack_qty} × {$record->pack_label_snapshot} ({$record->base_qty} units)"),
                            TextEntry::make('unit_price_net_e4')->label('Unit net')->formatStateUsing(fn (int $state) => MoneyFormatter::e4($state)),
                            TextEntry::make('tax_rate_bp')->label('VAT rate')->formatStateUsing(fn (int $state) => InvoiceDocumentBuilder::percent($state)),
                            TextEntry::make('line_net_minor')->label('Net')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state)),
                            TextEntry::make('line_tax_minor')->label('VAT')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state)),
                        ])
                        ->columns(8),
                ]),

            Section::make('Totals')
                ->schema([
                    self::moneyEntry('subtotal_net_minor', 'Goods net'),
                    self::moneyEntry('discount_net_minor', 'Discount (included)'),
                    self::moneyEntry('shipping_net_minor', 'Carriage net'),
                    self::moneyEntry('tax_minor', 'VAT'),
                    self::moneyEntry('total_gross_minor', 'Total'),
                    self::moneyEntry('paid_minor', 'Paid'),
                ])
                ->columns(6),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company:id,name', 'order:id,order_number,user_id', 'order.user:id,first_name,last_name,email', 'archivedPdf']))
            ->columns([
                TextColumn::make('invoice_number')->label('Number')->searchable()->sortable(),
                TextColumn::make('kind')
                    ->state(fn (Invoice $record) => $record->isReceipt() ? 'Receipt' : 'Invoice')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Receipt' ? 'gray' : 'info'),
                TextColumn::make('customer')->state(fn (Invoice $record) => self::customerName($record)),
                TextColumn::make('order.order_number')->label('Order')->searchable(),
                TextColumn::make('issued_at')->label('Issued')->date()->sortable(),
                TextColumn::make('due_at')->label('Due')->date()->placeholder('—')->sortable(),
                TextColumn::make('total_gross_minor')->label('Total')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state))->alignEnd(),
                TextColumn::make('paid_minor')->label('Paid')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state))->alignEnd()->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'part_paid' => 'warning',
                        'overdue' => 'danger',
                        'credited', 'void' => 'gray',
                        default => 'info',
                    }),
            ])
            ->actions([
                ViewAction::make(),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Invoice $record) => $record->archivedPdf !== null)
                    ->authorize('view')
                    ->action(fn (Invoice $record) => self::downloadPdf($record)),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::STATUSES),
                TernaryFilter::make('receipt')
                    ->label('Kind')
                    ->trueLabel('Receipts')
                    ->falseLabel('Invoices')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('company_id'),
                        false: fn (Builder $query) => $query->whereNotNull('company_id'),
                    ),
            ])
            ->defaultSort('issued_at', 'desc');
    }

    /**
     * The archived PDF, streamed through the application — never a public
     * storage URL (07 §6.3). Callers show the action only once the
     * document has been rendered, and authorise it through InvoicePolicy.
     */
    public static function downloadPdf(Invoice $record): ?StreamedResponse
    {
        $pdf = $record->archivedPdf;
        if ($pdf === null) {
            return null;
        }

        return Storage::disk($pdf->disk)->download($pdf->path, "{$record->invoice_number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }

    private static function customerName(Invoice $record): ?string
    {
        if ($record->company !== null) {
            return $record->company->name;
        }

        $user = $record->order?->user;
        if ($user === null) {
            return null;
        }

        $name = trim($user->first_name.' '.$user->last_name);

        return $name !== '' ? $name : $user->email;
    }

    private static function moneyEntry(string $column, string $label): TextEntry
    {
        return TextEntry::make($column)->label($label)->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state));
    }
}
