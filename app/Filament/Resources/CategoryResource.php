<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Support\CategoryTree;
use App\Models\Category;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identity')
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
                    Select::make('status')
                        ->options([
                            'active' => 'Active',
                            'hidden' => 'Hidden',
                            'archived' => 'Archived',
                        ])
                        ->default('active')
                        ->required(),
                ])
                ->columns(2),

            Section::make('Hierarchy')
                ->schema([
                    Select::make('parent_id')
                        ->label('Parent category')
                        ->options(fn (?Category $record): array => CategoryTree::options(excludingSubtreeRootId: $record?->id))
                        ->searchable()
                        ->helperText('Leave blank for a top-level category. A category can never be moved under itself or one of its own descendants.'),
                    TextInput::make('position')
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->helperText('Sort order among siblings.'),
                ])
                ->columns(2),

            Section::make('Metadata')
                ->schema([
                    TextInput::make('meta_title')->maxLength(255),
                    TextInput::make('meta_description')->maxLength(255),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex(),
                TextColumn::make('name')
                    ->label('Category')
                    ->formatStateUsing(fn (Category $record, string $state): string => str_repeat('— ', $record->depth).$state)
                    ->searchable(),
                TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'hidden' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('depth')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('position')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'hidden' => 'Hidden',
                        'archived' => 'Archived',
                    ]),
            ]);
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
