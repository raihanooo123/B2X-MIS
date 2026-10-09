<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers\SkusRelationManager;
use App\Filament\Support\CatalogueStatus;
use App\Filament\Support\CategoryTree;
use App\Filament\Support\MoneyFormatter;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Throwable;

/**
 * Doc 02 §5.4 — products. Part 2's core resource; carries
 * SkusRelationManager ("a relation manager for Sku").
 */
class ProductResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Product::class;

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    private const TYPES = [
        'simple' => 'Simple',
        'variant' => 'Variant parent',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Product details')
                            ->description('What this product is called and how it is described to customers.')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old) {
                                        // Only auto-fill while the slug still matches
                                        // the previous name — an admin's own edit to
                                        // the slug is never silently overwritten.
                                        $currentSlug = $get('slug');
                                        if ($currentSlug === null || $currentSlug === '' || $currentSlug === Str::slug($old ?? '')) {
                                            $set('slug', Str::slug($state ?? ''));
                                        }
                                    })
                                    ->columnSpanFull(),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->prefix('/products/')
                                    ->unique(
                                        ignoreRecord: true,
                                        modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                                    )
                                    ->helperText('Auto-generated from the name; edit it directly to override.')
                                    ->columnSpanFull(),
                                Textarea::make('short_description')
                                    ->maxLength(500)
                                    ->rows(2)
                                    ->helperText('One or two lines for listings and search results.')
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->rows(8)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Section::make('Classification')
                            ->description('Where the product sits in the catalogue.')
                            ->schema([
                                Select::make('brand_id')
                                    ->label('Brand')
                                    ->relationship('brand', 'name')
                                    ->searchable()
                                    ->preload(),
                                Select::make('primary_category_id')
                                    ->label('Primary category')
                                    ->options(fn (): array => CategoryTree::options())
                                    ->searchable()
                                    ->required()
                                    ->helperText('Indented by depth, in tree order.'),
                                Select::make('product_type')
                                    ->options(self::TYPES)
                                    ->default('simple')
                                    ->required()
                                    ->helperText('A variant parent groups several SKUs that differ by an attribute (colour, size); a simple product has one.')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                Select::make('status')
                                    ->options(CatalogueStatus::LIFECYCLE)
                                    ->default('draft')
                                    ->required(),
                                DateTimePicker::make('published_at')
                                    ->label('Published')
                                    ->helperText('Leave blank to keep this product unpublished.'),
                                Toggle::make('is_featured')
                                    ->label('Featured'),
                            ]),

                        Section::make('Merchandising')
                            ->schema([
                                TextInput::make('rrp_minor')
                                    ->label('RRP')
                                    ->prefix('£')
                                    ->rule('regex:/^\d{1,9}(\.\d{1,2})?$/')
                                    ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minorToDecimalString($state))
                                    ->dehydrateStateUsing(fn (?string $state): ?int => MoneyFormatter::decimalStringToMinor($state))
                                    ->helperText('Recommended retail price in pounds and pence, e.g. 12.99.'),
                            ]),

                        Section::make('Record')
                            ->hiddenOn('create')
                            ->schema([
                                Placeholder::make('created_at')
                                    ->label('Created')
                                    ->content(fn (?Product $record): string => $record?->created_at?->format('j M Y, H:i') ?? '—'),
                                Placeholder::make('updated_at')
                                    ->label('Last changed')
                                    ->content(fn (?Product $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
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
                        InfolistSection::make('Product details')
                            ->schema([
                                ImageEntry::make('thumbnail')
                                    ->hiddenLabel()
                                    ->state(fn (Product $record): ?string => self::thumbnailUrl($record))
                                    ->height(96)
                                    ->extraImgAttributes(['class' => 'rounded-lg object-cover'])
                                    ->visible(fn (Product $record): bool => self::thumbnailUrl($record) !== null)
                                    ->columnSpanFull(),
                                TextEntry::make('name')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('slug')
                                    ->prefix('/products/')
                                    ->copyable()
                                    ->color('gray'),
                                TextEntry::make('product_type')
                                    ->label('Type')
                                    ->formatStateUsing(fn (string $state): string => self::TYPES[$state] ?? $state),
                                TextEntry::make('short_description')
                                    ->placeholder('No short description.')
                                    ->columnSpanFull(),
                                TextEntry::make('description')
                                    ->placeholder('No description.')
                                    ->prose()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        InfolistSection::make('Classification')
                            ->schema([
                                TextEntry::make('brand.name')
                                    ->label('Brand')
                                    ->placeholder('No brand')
                                    ->url(fn (Product $record): ?string => $record->brand_id === null ? null : BrandResource::getUrl('view', ['record' => $record->brand_id])),
                                TextEntry::make('primaryCategory.name')
                                    ->label('Primary category')
                                    ->url(fn (Product $record): string => CategoryResource::getUrl('view', ['record' => $record->primary_category_id])),
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
                                TextEntry::make('published_at')
                                    ->label('Published')
                                    ->dateTime('j M Y, H:i')
                                    ->placeholder('Not published'),
                                IconEntry::make('is_featured')
                                    ->label('Featured')
                                    ->boolean(),
                            ]),

                        InfolistSection::make('Merchandising')
                            ->schema([
                                TextEntry::make('rrp_minor')
                                    ->label('RRP')
                                    ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minor($state))
                                    ->placeholder('Not set'),
                                TextEntry::make('skus_count')
                                    ->label('SKUs')
                                    ->state(fn (Product $record): int => $record->skus()->count()),
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
                ->with(['brand', 'primaryCategory', 'primaryImage'])
                ->withCount('skus'))
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->color('gray'),
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->square()
                    ->size(40)
                    ->extraImgAttributes(['class' => 'rounded-md'])
                    ->state(fn (Product $record): ?string => self::thumbnailUrl($record)),
                TextColumn::make('name')
                    ->label('Product')
                    ->weight('medium')
                    ->description(fn (Product $record): ?string => $record->primaryCategory?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('primaryCategory.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('skus_count')
                    ->label('SKUs')
                    ->counts('skus')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('rrp_minor')
                    ->label('RRP')
                    ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minor($state))
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogueStatus::label($state))
                    ->color(fn (string $state): string => CatalogueStatus::color($state))
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->date('j M Y')
                    ->placeholder('Not published')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make()->iconButton()->tooltip('View'),
                EditAction::make()->iconButton()->tooltip('Edit'),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('primary_category_id')
                    ->label('Category')
                    ->options(fn (): array => CategoryTree::options())
                    ->searchable(),
                SelectFilter::make('product_type')
                    ->label('Type')
                    ->options(self::TYPES),
                TernaryFilter::make('is_featured')
                    ->label('Featured'),
            ])
            ->filtersFormColumns(2)
            ->striped()
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('No products yet')
            ->emptyStateDescription('Create a product, then add its SKUs and packs.');
    }

    /** The product's first image, for the list and the detail page. */
    private static function thumbnailUrl(Product $record): ?string
    {
        $media = $record->primaryImage;

        if ($media === null) {
            return null;
        }

        try {
            return Storage::disk($media->disk)->url($media->path);
        } catch (Throwable) {
            return null;
        }
    }

    public static function getRelations(): array
    {
        return [
            SkusRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
