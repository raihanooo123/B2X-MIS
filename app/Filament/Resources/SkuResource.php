<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SkuResource\Pages;
use App\Filament\Resources\SkuResource\RelationManagers\PacksRelationManager;
use App\Filament\Support\CatalogueStatus;
use App\Filament\Support\MoneyFormatter;
use App\Models\Sku;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

/**
 * Doc 02 §5.5 — skus. Also reachable via ProductResource's own
 * SkusRelationManager (Part 2's "ProductResource with a relation
 * manager for Sku"); this is the standalone resource that relation
 * manager links out to, and the one that in turn carries the Packs
 * relation manager ("Sku with a relation manager for Pack").
 */
class SkuResource extends Resource
{
    protected static ?string $model = Sku::class;

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'SKU';

    protected static ?string $pluralModelLabel = 'SKUs';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'sku_code';

    private const BASE_UNITS = [
        'each' => 'Each',
        'kg' => 'Kilogram',
        'litre' => 'Litre',
        'metre' => 'Metre',
        'pair' => 'Pair',
    ];

    private const TRACKING_MODES = [
        'none' => 'None',
        'batch' => 'Batch',
        'serial' => 'Serial',
        'batch_and_serial' => 'Batch & serial',
    ];

    private const ALLOCATION_STRATEGIES = [
        'none' => 'None',
        'fifo' => 'FIFO',
        'fefo' => 'FEFO',
        'lifo' => 'LIFO',
    ];

    private const NON_REFUNDABLE_REASONS = [
        'consumable' => 'Consumable',
        'hygiene' => 'Hygiene',
        'electrical_sealed' => 'Electrical (sealed)',
        'bespoke' => 'Bespoke',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema(self::mainSections()),
                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Product')
                            ->schema([
                                Select::make('product_id')
                                    ->label('Product')
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->helperText('The product this SKU belongs to.'),
                            ]),
                        ...self::asideSections(),
                    ]),
            ]);
    }

    /**
     * The fields common to both this resource's own form and
     * ProductResource\RelationManagers\SkusRelationManager's — factored
     * out because the relation manager has no need for (and should not
     * show) the product_id selector: its parent product is already
     * implied by the relationship. The modal stacks them in one column.
     *
     * @return array<int, Section>
     */
    public static function coreFormSections(): array
    {
        return [...self::mainSections(), ...self::asideSections()];
    }

    /** @return array<int, Section> */
    private static function mainSections(): array
    {
        return [
            Section::make('Identity')
                ->description('The codes by which this SKU is known, here and by suppliers.')
                ->icon('heroicon-o-identification')
                ->schema([
                    TextInput::make('sku_code')
                        ->label('SKU code')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                        )
                        ->helperText('Must be unique among active SKUs.'),
                    TextInput::make('variant_label')
                        ->maxLength(255)
                        ->helperText('How this variant differs from its siblings, e.g. "500ml" or "Blue".'),
                    TextInput::make('barcode_ean')
                        ->label('Barcode (EAN)')
                        ->maxLength(255)
                        ->helperText('Used when a barcode is scanned on the order pad.'),
                    TextInput::make('supplier_ref')
                        ->label('Supplier reference')
                        ->maxLength(255),
                ])
                ->columns(2),

            Section::make('Ordering limits')
                ->description('In base units. A trade customer orders at least the minimum, in steps of the increment.')
                ->icon('heroicon-o-adjustments-horizontal')
                ->schema([
                    TextInput::make('moq_base_qty')
                        ->label('Minimum order quantity')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    TextInput::make('order_increment_base_qty')
                        ->label('Order increment')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    TextInput::make('max_order_base_qty')
                        ->label('Maximum order quantity')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave blank for no maximum. Must be at least the minimum order quantity.'),
                ])
                ->columns(3),

            Section::make('Stock tracking')
                ->description('How stock of this SKU is counted, allocated and aged.')
                ->icon('heroicon-o-archive-box')
                ->schema([
                    Toggle::make('is_stock_tracked')
                        ->label('Track stock')
                        ->default(true),
                    Toggle::make('allow_backorder')
                        ->label('Allow backorder'),
                    Toggle::make('requires_expiry')
                        ->label('Requires expiry date')
                        ->helperText('Only valid when tracking mode is batch or batch & serial.'),
                    Select::make('tracking_mode')
                        ->options(self::TRACKING_MODES)
                        ->default('none')
                        ->required()
                        ->helperText('Leave at "None" unless batch or serial tracking has been approved for this SKU.'),
                    Select::make('allocation_strategy')
                        ->options(self::ALLOCATION_STRATEGIES)
                        ->default('none')
                        ->required(),
                    TextInput::make('shelf_life_days')
                        ->label('Shelf life')
                        ->suffix('days')
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('min_remaining_shelf_life_days')
                        ->label('Minimum remaining shelf life')
                        ->suffix('days')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(3),

            Section::make('Returns')
                ->icon('heroicon-o-arrow-uturn-left')
                ->schema([
                    Toggle::make('is_refundable')
                        ->label('Refundable')
                        ->live()
                        ->default(true),
                    Select::make('non_refundable_reason')
                        ->options(self::NON_REFUNDABLE_REASONS)
                        ->required(fn (Get $get): bool => ! $get('is_refundable'))
                        ->visible(fn (Get $get): bool => ! $get('is_refundable'))
                        ->dehydrated(fn (Get $get): bool => ! $get('is_refundable'))
                        ->helperText('Required when this SKU is not refundable.'),
                ])
                ->columns(2),
        ];
    }

    /** @return array<int, Section> */
    private static function asideSections(): array
    {
        return [
            Section::make('Status')
                ->schema([
                    Select::make('status')
                        ->options(CatalogueStatus::LIFECYCLE)
                        ->default('draft')
                        ->required()
                        ->helperText('Only active SKUs can be bought.'),
                ]),

            Section::make('Tax & unit')
                ->schema([
                    Select::make('tax_class_id')
                        ->label('Tax class')
                        ->relationship('taxClass', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('base_unit')
                        ->options(self::BASE_UNITS)
                        ->default('each')
                        ->required(),
                    TextInput::make('unit_weight_g')
                        ->label('Unit weight')
                        ->suffix('g')
                        ->numeric()
                        ->minValue(0),
                ]),

            Section::make('Cost')
                ->visible(fn (): bool => self::canSeeCost())
                ->schema([
                    Placeholder::make('current_cost')
                        ->label('Current landed cost')
                        ->content(fn (?Sku $record): string => $record === null ? 'Available once the SKU is saved.' : self::currentCost($record))
                        ->helperText('Visible only to admin, rep and purchasing staff.'),
                ]),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                InfolistGroup::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        InfolistSection::make('Identity')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                TextEntry::make('sku_code')
                                    ->label('SKU code')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->fontFamily('mono')
                                    ->copyable(),
                                TextEntry::make('product.name')
                                    ->label('Product')
                                    ->url(fn (Sku $record): string => ProductResource::getUrl('view', ['record' => $record->product_id])),
                                TextEntry::make('variant_label')->placeholder('—'),
                                TextEntry::make('barcode_ean')->label('Barcode (EAN)')->fontFamily('mono')->copyable()->placeholder('—'),
                                TextEntry::make('supplier_ref')->label('Supplier reference')->placeholder('—'),
                            ])
                            ->columns(2),

                        InfolistSection::make('Ordering limits')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                TextEntry::make('moq_base_qty')->label('Minimum order quantity')->numeric()->suffix(fn (Sku $record): string => ' '.$record->base_unit),
                                TextEntry::make('order_increment_base_qty')->label('Order increment')->numeric()->suffix(fn (Sku $record): string => ' '.$record->base_unit),
                                TextEntry::make('max_order_base_qty')->label('Maximum order quantity')->numeric()->placeholder('No maximum'),
                            ])
                            ->columns(3),

                        InfolistSection::make('Stock tracking')
                            ->icon('heroicon-o-archive-box')
                            ->schema([
                                IconEntry::make('is_stock_tracked')->label('Track stock')->boolean(),
                                IconEntry::make('allow_backorder')->label('Allow backorder')->boolean(),
                                IconEntry::make('requires_expiry')->label('Requires expiry date')->boolean(),
                                TextEntry::make('tracking_mode')->formatStateUsing(fn (string $state): string => self::TRACKING_MODES[$state] ?? $state),
                                TextEntry::make('allocation_strategy')->formatStateUsing(fn (string $state): string => self::ALLOCATION_STRATEGIES[$state] ?? $state),
                                TextEntry::make('shelf_life_days')->label('Shelf life')->suffix(' days')->placeholder('—'),
                                TextEntry::make('min_remaining_shelf_life_days')->label('Minimum remaining shelf life')->suffix(' days')->placeholder('—'),
                            ])
                            ->columns(3),

                        InfolistSection::make('Returns')
                            ->icon('heroicon-o-arrow-uturn-left')
                            ->schema([
                                IconEntry::make('is_refundable')->label('Refundable')->boolean(),
                                TextEntry::make('non_refundable_reason')
                                    ->label('Not refundable because')
                                    ->formatStateUsing(fn (?string $state): string => self::NON_REFUNDABLE_REASONS[$state] ?? (string) $state)
                                    ->visible(fn (Sku $record): bool => ! $record->is_refundable),
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
                                    ->formatStateUsing(fn (string $state): string => CatalogueStatus::label($state))
                                    ->icon(fn (string $state): string => CatalogueStatus::icon($state))
                                    ->color(fn (string $state): string => CatalogueStatus::color($state)),
                            ]),

                        InfolistSection::make('Tax & unit')
                            ->schema([
                                TextEntry::make('taxClass.name')->label('Tax class'),
                                TextEntry::make('base_unit')->formatStateUsing(fn (string $state): string => self::BASE_UNITS[$state] ?? $state),
                                TextEntry::make('unit_weight_g')->label('Unit weight')->numeric()->suffix(' g')->placeholder('—'),
                            ]),

                        InfolistSection::make('Cost')
                            ->visible(fn (): bool => self::canSeeCost())
                            ->schema([
                                TextEntry::make('current_cost')
                                    ->label('Current landed cost')
                                    ->state(fn (Sku $record): string => self::currentCost($record))
                                    ->helperText('Visible only to admin, rep and purchasing staff.'),
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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['product', 'taxClass', 'defaultPack'])
                ->withCount('packs'))
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->color('gray'),
                TextColumn::make('sku_code')
                    ->label('SKU')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('SKU code copied')
                    ->description(fn (Sku $record): string => $record->variant_label ?? '')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->limit(48)
                    ->tooltip(fn (Sku $record): ?string => $record->product?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('barcode_ean')
                    ->label('Barcode')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('defaultPack.label')
                    ->label('Default pack')
                    ->description(fn (Sku $record): string => $record->defaultPack === null ? '' : $record->defaultPack->base_units.' × '.$record->base_unit)
                    ->placeholder('None set')
                    ->toggleable(),
                TextColumn::make('packs_count')
                    ->label('Packs')
                    ->counts('packs')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogueStatus::label($state))
                    ->color(fn (string $state): string => CatalogueStatus::color($state))
                    ->sortable(),
                TextColumn::make('taxClass.name')
                    ->label('Tax class')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_stock_tracked')
                    ->label('Tracked')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_mode')
                    ->label('Tracking')
                    ->formatStateUsing(fn (string $state): string => self::TRACKING_MODES[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->filters([
                SelectFilter::make('tax_class_id')
                    ->label('Tax class')
                    ->relationship('taxClass', 'name'),
                SelectFilter::make('tracking_mode')
                    ->label('Tracking')
                    ->options(self::TRACKING_MODES),
                TernaryFilter::make('is_stock_tracked')
                    ->label('Stock tracked'),
            ])
            ->filtersFormColumns(2)
            ->striped()
            ->defaultSort('sku_code')
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateHeading('No SKUs yet')
            ->emptyStateDescription('Add SKUs from a product, or create one here.');
    }

    private static function canSeeCost(): bool
    {
        return Auth::user()?->hasAnyRole(['admin', 'rep', 'purchasing']) ?? false;
    }

    private static function currentCost(Sku $record): string
    {
        $cost = $record->costs()->first();

        return $cost === null ? 'No cost history yet.' : (MoneyFormatter::e4($cost->landed_cost_e4) ?? '—');
    }

    public static function getRelations(): array
    {
        return [
            PacksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkus::route('/'),
            'create' => Pages\CreateSku::route('/create'),
            'view' => Pages\ViewSku::route('/{record}'),
            'edit' => Pages\EditSku::route('/{record}/edit'),
        ];
    }
}
