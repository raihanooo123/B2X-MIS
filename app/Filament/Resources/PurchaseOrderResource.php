<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Resources\PurchaseOrderResource\RelationManagers\LinesRelationManager;
use App\Filament\Support\MoneyFormatter;
use App\Filament\Support\PurchasingStatus;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'po_number';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Goods')
                            ->description('What you are buying, in the supplier\'s packs, at the supplier\'s price per base unit in pounds. Stock can be received only after the supplier confirms the order.')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                Repeater::make('lines')
                                    ->hiddenLabel()
                                    ->minItems(1)
                                    ->defaultItems(1)
                                    ->required()
                                    ->addActionLabel('Add line')
                                    ->itemLabel(fn (array $state): string => self::lineLabel($state))
                                    ->collapsible()
                                    ->schema([
                                        Select::make('sku_id')
                                            ->label('SKU')
                                            ->options(fn (): array => Sku::query()->where('status', 'active')->where('is_stock_tracked', true)->orderBy('sku_code')->pluck('sku_code', 'id')->all())
                                            ->searchable()
                                            ->live()
                                            ->afterStateUpdated(fn (Set $set) => $set('pack_id', null))
                                            ->required()
                                            ->columnSpan(['md' => 2]),
                                        Select::make('pack_id')
                                            ->label('Pack')
                                            ->options(fn (Get $get): array => Pack::query()->where('sku_id', (int) $get('sku_id'))->orderBy('base_units')->get()->mapWithKeys(fn (Pack $pack) => [$pack->id => "{$pack->label} ({$pack->base_units} units)"])->all())
                                            ->required()
                                            ->columnSpan(['md' => 2]),
                                        TextInput::make('pack_qty')
                                            ->label('Number of packs')
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->required()
                                            ->columnSpan(['md' => 2]),
                                        TextInput::make('unit_fob')
                                            ->label('Supplier price per base unit')
                                            ->prefix('£')
                                            ->rule('regex:/^\d{1,7}(\.\d{1,4})?$/')
                                            ->required()
                                            ->helperText('Before freight and duty. Up to four decimal places, e.g. 0.4125.')
                                            ->columnSpan(['md' => 2]),
                                    ])
                                    ->columns(['md' => 4])
                                    ->reorderableWithButtons(),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Supplier & delivery')
                            ->schema([
                                Select::make('supplier_id')
                                    ->label('Supplier')
                                    ->options(fn (): array => Supplier::query()->where('status', 'active')->where('default_currency', 'GBP')->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->helperText('Only active suppliers who trade in pounds are listed.'),
                                Select::make('location_id')
                                    ->label('Receive at')
                                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->required(),
                                DatePicker::make('expected_at')
                                    ->label('Expected delivery'),
                            ]),

                        Section::make('Reference & notes')
                            ->schema([
                                TextInput::make('supplier_reference')
                                    ->label('Supplier reference')
                                    ->maxLength(255)
                                    ->helperText('Their quote or order number, if they gave one.'),
                                Textarea::make('note')
                                    ->rows(4)
                                    ->helperText('For staff only.'),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                InfolistGroup::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        InfolistSection::make('Order')
                            ->schema([
                                TextEntry::make('po_number')
                                    ->label('PO number')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->fontFamily('mono')
                                    ->copyable(),
                                TextEntry::make('supplier.name')
                                    ->label('Supplier')
                                    ->url(fn (PurchaseOrder $record): string => SupplierResource::getUrl('view', ['record' => $record->supplier_id])),
                                TextEntry::make('location.name')->label('Receive at'),
                                TextEntry::make('expected_at')
                                    ->label('Expected delivery')
                                    ->date('j M Y')
                                    ->placeholder('Not set')
                                    ->color(fn (PurchaseOrder $record): ?string => self::isOverdue($record) ? 'danger' : null),
                                TextEntry::make('supplier_reference')->label('Supplier reference')->placeholder('—'),
                                TextEntry::make('incoterm')->label('Delivery terms'),
                                TextEntry::make('note')->placeholder('No notes.')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),

                InfolistGroup::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        InfolistSection::make('Status')
                            ->schema([
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => PurchasingStatus::label($state))
                                    ->icon(fn (string $state): string => PurchasingStatus::icon($state))
                                    ->color(fn (string $state): string => PurchasingStatus::color($state)),
                                TextEntry::make('received_progress')
                                    ->label('Received')
                                    ->state(fn (PurchaseOrder $record): string => self::receivedProgress($record)),
                            ]),

                        InfolistSection::make('Totals')
                            ->schema([
                                TextEntry::make('goods_total_minor')
                                    ->label('Goods total')
                                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::minor($state))
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold'),
                                TextEntry::make('currency'),
                            ])
                            ->columns(2),

                        InfolistSection::make('Record')
                            ->schema([
                                TextEntry::make('created_at')->label('Raised')->dateTime('j M Y, H:i'),
                                TextEntry::make('ordered_at')->label('Confirmed')->dateTime('j M Y, H:i')->placeholder('Not yet'),
                                TextEntry::make('received_at')->label('Fully received')->dateTime('j M Y, H:i')->placeholder('Not yet'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'location'])->withCount('lines'))
            ->columns([
                TextColumn::make('po_number')
                    ->label('PO')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->description(fn (PurchaseOrder $record): ?string => $record->supplier_reference)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable(),
                TextColumn::make('location.code')
                    ->label('Receive at')
                    ->toggleable(),
                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('goods_total_minor')
                    ->label('Goods total')
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::minor($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('expected_at')
                    ->label('Expected')
                    ->date('j M Y')
                    ->placeholder('Not set')
                    ->color(fn (PurchaseOrder $record): ?string => self::isOverdue($record) ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PurchasingStatus::label($state))
                    ->color(fn (string $state): string => PurchasingStatus::color($state)),
                TextColumn::make('created_at')
                    ->label('Raised')
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('location_id')
                    ->label('Receive at')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()->visible(fn (PurchaseOrder $record): bool => $record->status === 'draft'),
                ]),
            ])
            ->striped()
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('No purchase orders yet')
            ->emptyStateDescription('Raise a purchase order to bring stock in from a supplier.');
    }

    /**
     * "SKU-123 · 5 packs" on a collapsed line in the form.
     *
     * @param  array<string, mixed>  $state
     */
    private static function lineLabel(array $state): string
    {
        $skuId = $state['sku_id'] ?? null;
        if (! is_numeric($skuId)) {
            return 'New line';
        }

        $code = Sku::query()->whereKey((int) $skuId)->value('sku_code');
        $packs = $state['pack_qty'] ?? null;

        return is_numeric($packs) ? "{$code} · {$packs} ".((int) $packs === 1 ? 'pack' : 'packs') : (string) $code;
    }

    /** Due date passed while goods are still owed. */
    private static function isOverdue(PurchaseOrder $record): bool
    {
        return $record->expected_at !== null
            && $record->expected_at->isBefore(today())
            && in_array($record->status, ['sent', ...PurchaseOrder::RECEIVABLE_STATUSES], true);
    }

    /** "120 of 480 units". */
    private static function receivedProgress(PurchaseOrder $record): string
    {
        $totals = $record->lines()
            ->toBase()
            ->selectRaw('COALESCE(SUM(base_qty), 0) AS ordered, COALESCE(SUM(received_base_qty), 0) AS received')
            ->first();

        $ordered = (int) ($totals->ordered ?? 0);
        $received = (int) ($totals->received ?? 0);

        return "{$received} of {$ordered} units";
    }

    public static function unitCostInput(PurchaseOrderLine $line): string
    {
        return intdiv($line->unit_fob_e4, 10000).'.'.str_pad((string) ($line->unit_fob_e4 % 10000), 4, '0', STR_PAD_LEFT);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
