<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Filament\Support\CatalogueStatus;
use App\Models\Brand;
use Filament\Forms\Components\Group;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Doc 02 §5.9 — brands.
 */
class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Brand details')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, ?string $old) {
                                        $currentSlug = $get('slug');
                                        if ($currentSlug === null || $currentSlug === '' || $currentSlug === Str::slug($old ?? '')) {
                                            $set('slug', Str::slug($state ?? ''));
                                        }
                                    }),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Auto-generated from the name; edit it directly to override.'),
                                Textarea::make('description')
                                    ->rows(5)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Section::make('Search engines')
                            ->description('How this brand appears in search results. Leave blank to use the name.')
                            ->icon('heroicon-o-magnifying-glass')
                            ->collapsible()
                            ->schema([
                                TextInput::make('meta_title')->maxLength(255),
                                TextInput::make('meta_description')->maxLength(255),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                Select::make('status')
                                    ->options(CatalogueStatus::VISIBILITY)
                                    ->default('active')
                                    ->required(),
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
                        InfolistSection::make('Brand details')
                            ->schema([
                                TextEntry::make('name')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('slug')->copyable()->color('gray'),
                                TextEntry::make('description')->placeholder('No description.')->prose()->columnSpanFull(),
                            ])
                            ->columns(2),

                        InfolistSection::make('Search engines')
                            ->icon('heroicon-o-magnifying-glass')
                            ->schema([
                                TextEntry::make('meta_title')->placeholder('Uses the name'),
                                TextEntry::make('meta_description')->placeholder('Not set'),
                            ]),
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
                                TextEntry::make('products_count')
                                    ->label('Products')
                                    ->state(fn (Brand $record): int => $record->products()->count())
                                    ->url(fn (Brand $record): string => ProductResource::getUrl('index', ['tableFilters' => ['brand_id' => ['value' => $record->id]]])),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('products'))
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->color('gray'),
                TextColumn::make('name')
                    ->label('Brand')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogueStatus::label($state))
                    ->color(fn (string $state): string => CatalogueStatus::color($state))
                    ->sortable(),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->striped()
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateHeading('No brands yet')
            ->emptyStateDescription('Brands group products for filtering on the storefront and order pad.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'view' => Pages\ViewBrand::route('/{record}'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
