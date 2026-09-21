<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SkuResource\Pages;
use App\Filament\Resources\SkuResource\RelationManagers\PacksRelationManager;
use App\Filament\Support\MoneyFormatter;
use App\Models\Sku;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'sku_code';

    public static function form(Form $form): Form
    {
        return $form->schema([
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
            ...static::coreFormSections(),
        ]);
    }

    /**
     * The fields common to both this resource's own form and
     * ProductResource\RelationManagers\SkusRelationManager's — factored
     * out because the relation manager has no need for (and should not
     * show) the product_id selector above: its parent product is
     * already implied by the relationship.
     *
     * @return array<int, Section>
     */
    public static function coreFormSections(): array
    {
        return [
            Section::make('Identity')
                ->description('The code and status by which this SKU is known.')
                ->schema([
                    TextInput::make('sku_code')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                        )
                        ->helperText('Unique across active SKUs (skus_sku_code_uq). A duplicate is rejected here, before it ever reaches the database.'),
                    TextInput::make('barcode_ean')
                        ->label('Barcode (EAN)')
                        ->maxLength(255)
                        ->helperText('Matched against a scanned barcode on the order pad (05.1 §8.2).'),
                    TextInput::make('supplier_ref')
                        ->label('Supplier reference')
                        ->maxLength(255),
                    TextInput::make('variant_label')
                        ->maxLength(255)
                        ->helperText('How this variant differs from its siblings, e.g. "500ml" or "Blue".'),
                    Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'active' => 'Active',
                            'coming_soon' => 'Coming soon',
                            'discontinued' => 'Discontinued',
                            'archived' => 'Archived',
                        ])
                        ->default('draft')
                        ->required()
                        ->helperText('Only "Active" SKUs are purchasable (03 §4.6).'),
                ])
                ->columns(2),

            Section::make('Tax & unit')
                ->schema([
                    Select::make('tax_class_id')
                        ->label('Tax class')
                        ->relationship('taxClass', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('base_unit')
                        ->options([
                            'each' => 'Each',
                            'kg' => 'Kilogram',
                            'litre' => 'Litre',
                            'metre' => 'Metre',
                            'pair' => 'Pair',
                        ])
                        ->default('each')
                        ->required(),
                    TextInput::make('unit_weight_g')
                        ->label('Unit weight (g)')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(3),

            Section::make('Ordering limits')
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
                        ->helperText('Leave blank for no maximum. Must be at least the minimum order quantity (skus_max_chk).'),
                ])
                ->columns(3),

            Section::make('Stock tracking')
                ->schema([
                    Toggle::make('is_stock_tracked')->default(true),
                    Select::make('tracking_mode')
                        ->options([
                            'none' => 'None',
                            'batch' => 'Batch',
                            'serial' => 'Serial',
                            'batch_and_serial' => 'Batch & serial',
                        ])
                        ->default('none')
                        ->required()
                        ->helperText('Which SKUs enable batch/serial tracking at launch is an open decision (CLAUDE.md) — leave at "None" unless explicitly approved.'),
                    Select::make('allocation_strategy')
                        ->options([
                            'none' => 'None',
                            'fifo' => 'FIFO',
                            'fefo' => 'FEFO',
                            'lifo' => 'LIFO',
                        ])
                        ->default('none')
                        ->required(),
                    Toggle::make('requires_expiry')
                        ->helperText('Only valid when tracking mode is batch or batch & serial (skus_expiry_chk).'),
                    TextInput::make('shelf_life_days')->numeric()->minValue(1),
                    TextInput::make('min_remaining_shelf_life_days')->numeric()->minValue(0),
                    Toggle::make('allow_backorder'),
                ])
                ->columns(3),

            Section::make('Returns')
                ->schema([
                    Toggle::make('is_refundable')
                        ->live()
                        ->default(true),
                    Select::make('non_refundable_reason')
                        ->options([
                            'consumable' => 'Consumable',
                            'hygiene' => 'Hygiene',
                            'electrical_sealed' => 'Electrical (sealed)',
                            'bespoke' => 'Bespoke',
                        ])
                        ->required(fn (Get $get): bool => ! $get('is_refundable'))
                        ->visible(fn (Get $get): bool => ! $get('is_refundable'))
                        ->dehydrated(fn (Get $get): bool => ! $get('is_refundable'))
                        ->helperText('Required when this SKU is not refundable (skus_nonref_chk).'),
                ])
                ->columns(2),

            Section::make('Cost')
                ->visible(fn (): bool => Auth::user()?->hasAnyRole(['admin', 'rep', 'purchasing']) ?? false)
                ->schema([
                    Placeholder::make('current_cost')
                        ->label('Current landed cost')
                        ->content(function (?Sku $record): string {
                            if ($record === null) {
                                return 'Available once the SKU is saved.';
                            }

                            $cost = $record->costs()->first();

                            return $cost === null
                                ? 'No cost history yet.'
                                : (MoneyFormatter::e4($cost->landed_cost_e4) ?? '—');
                        })
                        ->helperText('Cost figures never reach customer-facing contexts (CLAUDE.md invariant 9) — visible here only to admin, rep and purchasing staff.'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'taxClass']))
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex(),

                TextColumn::make('sku_code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant_label')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'gray',
                        'coming_soon' => 'info',
                        'discontinued' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('taxClass.name')
                    ->label('Tax class')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_stock_tracked')
                    ->label('Tracked')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_mode')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'coming_soon' => 'Coming soon',
                        'discontinued' => 'Discontinued',
                        'archived' => 'Archived',
                    ]),
                SelectFilter::make('tax_class_id')
                    ->label('Tax class')
                    ->relationship('taxClass', 'name'),
            ])
            ->defaultSort('sku_code');
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
            'edit' => Pages\EditSku::route('/{record}/edit'),
        ];
    }
}
