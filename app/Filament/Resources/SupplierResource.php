<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Resources\SupplierResource\RelationManagers\PurchaseOrdersRelationManager;
use App\Filament\Support\PurchasingStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
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

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    private const INCOTERMS = [
        'EXW' => 'EXW — collect from the supplier',
        'FOB' => 'FOB — supplier loads at their port',
        'CIF' => 'CIF — supplier pays freight and insurance',
        'CFR' => 'CFR — supplier pays freight',
        'DAP' => 'DAP — delivered, duty unpaid',
        'DDP' => 'DDP — delivered, duty paid',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Supplier details')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('code')
                                    ->required()
                                    ->maxLength(64)
                                    ->unique(ignoreRecord: true)
                                    ->helperText('A short code of your own, e.g. ACME. Must be unique.'),
                            ])
                            ->columns(2),

                        Section::make('Contact')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextInput::make('contact_name')
                                    ->label('Name')
                                    ->maxLength(255),
                                TextInput::make('contact_email')
                                    ->label('Email')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('contact_phone')
                                    ->label('Phone')
                                    ->tel()
                                    ->maxLength(64),
                            ])
                            ->columns(3),

                        Section::make('Notes')
                            ->icon('heroicon-o-pencil-square')
                            ->schema([
                                Textarea::make('note')
                                    ->hiddenLabel()
                                    ->rows(4)
                                    ->helperText('For staff only. Never shown to the supplier.'),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                Select::make('status')
                                    ->options(PurchasingStatus::SUPPLIER)
                                    ->default('active')
                                    ->required()
                                    ->helperText('New purchase orders can be raised only with active suppliers.'),
                            ]),

                        Section::make('Trading terms')
                            ->schema([
                                TextInput::make('country_code')
                                    ->label('Country code')
                                    ->required()
                                    ->length(2)
                                    ->default('GB')
                                    ->helperText('Two letters, e.g. GB, CN, DE.'),
                                Select::make('default_currency')
                                    ->label('Currency')
                                    ->options(['GBP' => 'GBP — pound sterling'])
                                    ->default('GBP')
                                    ->required()
                                    ->helperText('Purchase orders are in pounds for now.'),
                                Select::make('default_incoterm')
                                    ->label('Delivery terms')
                                    ->options(self::INCOTERMS)
                                    ->default('FOB')
                                    ->required()
                                    ->helperText('Who pays for shipping and from where. Used as the default on new purchase orders.'),
                                TextInput::make('lead_time_days')
                                    ->label('Lead time')
                                    ->suffix('days')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->helperText('Usual days from order to delivery. Reorder suggestions use it.'),
                                TextInput::make('payment_terms')
                                    ->maxLength(255)
                                    ->placeholder('e.g. 30 days from invoice'),
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
                        InfolistSection::make('Supplier details')
                            ->schema([
                                TextEntry::make('name')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('code')->fontFamily('mono')->copyable(),
                                TextEntry::make('country_code')->label('Country'),
                            ])
                            ->columns(2),

                        InfolistSection::make('Contact')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextEntry::make('contact_name')->label('Name')->placeholder('—'),
                                TextEntry::make('contact_email')->label('Email')->placeholder('—')->copyable()
                                    ->url(fn (Supplier $record): ?string => $record->contact_email === null ? null : 'mailto:'.$record->contact_email),
                                TextEntry::make('contact_phone')->label('Phone')->placeholder('—')->copyable(),
                            ])
                            ->columns(3),

                        InfolistSection::make('Notes')
                            ->icon('heroicon-o-pencil-square')
                            ->schema([
                                TextEntry::make('note')->hiddenLabel()->placeholder('No notes.'),
                            ]),
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
                                TextEntry::make('open_orders')
                                    ->label('Open purchase orders')
                                    ->state(fn (Supplier $record): int => $record->purchaseOrders()->whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES)->count()),
                            ]),

                        InfolistSection::make('Trading terms')
                            ->schema([
                                TextEntry::make('default_currency')->label('Currency'),
                                TextEntry::make('default_incoterm')->label('Delivery terms')
                                    ->formatStateUsing(fn (string $state): string => self::INCOTERMS[$state] ?? $state),
                                TextEntry::make('lead_time_days')->label('Lead time')->suffix(' days')->placeholder('Not set'),
                                TextEntry::make('payment_terms')->placeholder('Not set'),
                            ]),

                        InfolistSection::make('Record')
                            ->schema([
                                TextEntry::make('created_at')->label('Created')->dateTime('j M Y, H:i'),
                                TextEntry::make('updated_at')->label('Last changed')->since(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'purchaseOrders as open_orders_count' => fn (Builder $po) => $po->whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES),
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Supplier')
                    ->weight('medium')
                    ->description(fn (Supplier $record): string => $record->code)
                    ->searchable(['name', 'code'])
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->label('Contact')
                    ->description(fn (Supplier $record): ?string => $record->contact_email)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('lead_time_days')
                    ->label('Lead time')
                    ->suffix(' days')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('open_orders_count')
                    ->label('Open POs')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('default_currency')
                    ->label('Currency')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PurchasingStatus::label($state))
                    ->color(fn (string $state): string => PurchasingStatus::color($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(fn (): array => Supplier::query()->distinct()->orderBy('country_code')->pluck('country_code', 'country_code')->all()),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->striped()
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-office')
            ->emptyStateHeading('No suppliers yet')
            ->emptyStateDescription('Add a supplier before raising a purchase order.');
    }

    public static function getRelations(): array
    {
        return [
            PurchaseOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view' => Pages\ViewSupplier::route('/{record}'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
