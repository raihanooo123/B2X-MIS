<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Support\CatalogueStatus;
use App\Filament\Support\CategoryTree;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Category;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Doc 02 §5.3 — categories. Part 3: "shown as an indented tree ordered
 * by path, with a parent selector." No column here is `->sortable()` —
 * deliberately: this table has exactly one order, tree order
 * (`ORDER BY path`, §CategoryPath's zero-padding note), and a sortable
 * column would let a click undo that.
 */
class CategoryResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Category::class;

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Category details')
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
                            ])
                            ->columns(2),

                        Section::make('Search engines')
                            ->description('How this category appears in search results. Leave blank to use the name.')
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

                        Section::make('Position in the tree')
                            ->schema([
                                Select::make('parent_id')
                                    ->label('Parent category')
                                    ->options(fn (?Category $record): array => CategoryTree::options(excludingSubtreeRootId: $record?->id))
                                    ->searchable()
                                    ->placeholder('Top level')
                                    ->helperText('Leave blank for a top-level category. A category can never be moved under itself or one of its own descendants.'),
                                TextInput::make('position')
                                    ->numeric()
                                    ->integer()
                                    ->default(0)
                                    ->helperText('Sort order among siblings.'),
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
                        InfolistSection::make('Category details')
                            ->schema([
                                TextEntry::make('name')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('slug')->copyable()->color('gray'),
                                TextEntry::make('trail')
                                    ->label('Path')
                                    ->state(fn (Category $record): string => self::trail($record)),
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
                            ]),

                        InfolistSection::make('Position in the tree')
                            ->schema([
                                TextEntry::make('parent.name')
                                    ->label('Parent category')
                                    ->placeholder('Top level')
                                    ->url(fn (Category $record): ?string => $record->parent_id === null ? null : self::getUrl('view', ['record' => $record->parent_id])),
                                TextEntry::make('depth')->label('Depth'),
                                TextEntry::make('position')->label('Sort order'),
                                TextEntry::make('children_count')
                                    ->label('Subcategories')
                                    ->state(fn (Category $record): int => $record->children()->count()),
                                TextEntry::make('products_count')
                                    ->label('Products')
                                    ->state(fn (Category $record): int => $record->products()->count()),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['children', 'products']))
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->color('gray'),
                TextColumn::make('name')
                    ->label('Category')
                    ->formatStateUsing(fn (Category $record, string $state): string => str_repeat('— ', $record->depth).$state)
                    ->weight(fn (Category $record): string => $record->depth === 0 ? 'semibold' : 'normal')
                    ->searchable(),
                TextColumn::make('slug')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('children_count')
                    ->label('Subcategories')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogueStatus::label($state))
                    ->color(fn (string $state): string => CatalogueStatus::color($state)),
                TextColumn::make('depth')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('position')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make()->iconButton()->tooltip('View'),
                EditAction::make()->iconButton()->tooltip('Edit'),
            ])
            ->emptyStateIcon('heroicon-o-folder')
            ->emptyStateHeading('No categories yet')
            ->emptyStateDescription('Create a top-level category, then nest others beneath it.');
    }

    /** "Parent › Child › This", walked up from the category. */
    private static function trail(Category $record): string
    {
        $names = [$record->name];
        $current = $record->parent;
        while ($current !== null && count($names) < 20) {
            array_unshift($names, $current->name);
            $current = $current->parent;
        }

        return implode(' › ', $names);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'view' => Pages\ViewCategory::route('/{record}'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
