<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers\SkusRelationManager;
use App\Filament\Support\CategoryTree;
use App\Filament\Support\MoneyFormatter;
use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identity')
                ->description('What this product is called and where it stands in its lifecycle.')
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
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                        )
                        ->helperText('Auto-generated from the name; edit it directly to override.'),
                    Select::make('product_type')
                        ->options([
                            'simple' => 'Simple',
                            'variant' => 'Variant parent',
                        ])
                        ->default('simple')
                        ->required()
                        ->helperText('A variant parent groups several SKUs that differ by an attribute (colour, size); a simple product has one.'),
                    Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'active' => 'Active',
                            'coming_soon' => 'Coming soon',
                            'discontinued' => 'Discontinued',
                            'archived' => 'Archived',
                        ])
                        ->default('draft')
                        ->required(),
                    Toggle::make('is_featured'),
                    DateTimePicker::make('published_at')
                        ->helperText('Leave blank to keep this product unpublished.'),
                ])
                ->columns(2),

            Section::make('Classification')
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
                ])
                ->columns(2),

            Section::make('Descriptions')
                ->schema([
                    Textarea::make('short_description')
                        ->maxLength(500)
                        ->rows(2),
                    Textarea::make('description')
                        ->rows(6),
                ]),

            Section::make('Merchandising')
                ->schema([
                    TextInput::make('rrp_minor')
                        ->label('RRP')
                        ->prefix('£')
                        ->rule('regex:/^\d{1,9}(\.\d{1,2})?$/')
                        ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minorToDecimalString($state))
                        ->dehydrateStateUsing(fn (?string $state): ?int => MoneyFormatter::decimalStringToMinor($state))
                        ->helperText('Recommended retail price, pounds and pence — e.g. 12.99. Stored as whole pence (products.rrp_minor); parsed as text, never as a float.'),
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
                    ->rowIndex(),

                ImageColumn::make('thumbnail')
                    ->label('')
                    ->square()
                    ->state(function (Product $record): ?string {
                        $media = $record->primaryImage;

                        if ($media === null) {
                            return null;
                        }

                        try {
                            return Storage::disk($media->disk)->url($media->path);
                        } catch (Throwable) {
                            return null;
                        }
                    }),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('primaryCategory.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                TextColumn::make('rrp_minor')
                    ->label('RRP')
                    ->formatStateUsing(fn (?int $state): ?string => MoneyFormatter::minor($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_featured')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('skus_count')
                    ->label('SKUs')
                    ->counts('skus')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
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
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('primary_category_id')
                    ->label('Category')
                    ->options(fn (): array => CategoryTree::options()),
            ])
            ->defaultSort('name');
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
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
