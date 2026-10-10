<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Support\MoneyFormatter;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Invoice;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
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
    use SentenceCaseLabels;

    protected static ?string $model = Invoice::class;

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?int $navigationSort = 10;

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

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'part_paid' => 'warning',
            'overdue' => 'danger',
            'credited', 'void' => 'gray',
            default => 'info',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Document')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                TextEntry::make('invoice_number')->label('Number')
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold')->fontFamily('mono')->copyable(),
                                TextEntry::make('kind')->state(fn (Invoice $record) => $record->isReceipt() ? 'Receipt' : 'VAT invoice'),
                                TextEntry::make('customer')->state(fn (Invoice $record) => self::customerName($record)),
                                TextEntry::make('order.order_number')->label('Order')->fontFamily('mono'),
                            ])
                            ->columns(2),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('status')->badge()
                                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                                    ->color(fn (string $state): string => self::statusColor($state)),
                                TextEntry::make('issued_at')->label('Issued')->dateTime(),
                                TextEntry::make('payment_terms')->label('Payment terms')->placeholder('Paid at order'),
                                TextEntry::make('due_at')->label('Due')->date()->placeholder('—'),
                            ]),

                        Section::make('Totals')
                            ->schema([
                                self::moneyEntry('subtotal_net_minor', 'Goods net'),
                                self::moneyEntry('discount_net_minor', 'Discount (included)'),
                                self::moneyEntry('shipping_net_minor', 'Carriage net'),
                                self::moneyEntry('tax_minor', 'VAT'),
                                self::moneyEntry('total_gross_minor', 'Total')->size(TextEntry\TextEntrySize::Large)->weight('semibold'),
                                self::moneyEntry('paid_minor', 'Paid'),
                                TextEntry::make('outstanding')->label('Still to pay')
                                    ->state(fn (Invoice $record): ?string => MoneyFormatter::minor($record->total_gross_minor - $record->paid_minor))
                                    ->color(fn (Invoice $record): ?string => $record->total_gross_minor > $record->paid_minor ? 'danger' : null),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company:id,name', 'order:id,order_number,user_id,guest_email', 'order.user:id,first_name,last_name,email', 'archivedPdf']))
            ->columns([
                TextColumn::make('invoice_number')->label('Number')->fontFamily('mono')->weight('medium')
                    ->description(fn (Invoice $record): string => $record->isReceipt() ? 'Receipt' : 'Invoice')
                    ->searchable()->sortable(),
                TextColumn::make('customer')->state(fn (Invoice $record) => self::customerName($record))
                    ->description(fn (Invoice $record): ?string => $record->order?->order_number),
                TextColumn::make('issued_at')->label('Issued')->date()->sortable()
                    ->description(fn (Invoice $record): ?string => $record->due_at === null ? null : 'Due '.$record->due_at->format('j M Y')),
                TextColumn::make('total_gross_minor')->label('Total')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state))->alignEnd()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('paid_minor')->label('Paid')->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order.order_number')->label('Order')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_at')->label('Due')->date()->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make()->iconButton()->tooltip('View'),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->iconButton()
                    ->tooltip('Download PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Invoice $record) => $record->archivedPdf !== null)
                    ->authorize('view')
                    ->action(fn (Invoice $record) => self::downloadPdf($record)),
            ])
            ->filters([
                TernaryFilter::make('receipt')
                    ->label('Kind')
                    ->trueLabel('Receipts')
                    ->falseLabel('Invoices')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('company_id'),
                        false: fn (Builder $query) => $query->whereNotNull('company_id'),
                    ),
            ])
            ->striped()
            ->defaultSort('issued_at', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('No invoices here')
            ->emptyStateDescription('Invoices and receipts are issued automatically when orders are paid or dispatched.');
    }

    public static function getRelations(): array
    {
        return [
            InvoiceResource\RelationManagers\LinesRelationManager::class,
        ];
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
            // 02 §26.1: a guest's receipt names the guest by email.
            return $record->order?->guest_email;
        }

        $name = trim($user->first_name.' '.$user->last_name);

        return $name !== '' ? $name : $user->email;
    }

    private static function moneyEntry(string $column, string $label): TextEntry
    {
        return TextEntry::make($column)->label($label)->formatStateUsing(fn (int $state) => MoneyFormatter::minor($state));
    }
}
