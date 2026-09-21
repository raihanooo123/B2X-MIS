<?php

namespace App\Filament\Resources\SkuResource\RelationManagers;

use App\Models\Pack;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Doc 02 §5.6 — packs, Part 2's "Sku with a relation manager for Pack."
 *
 * `is_default_sell`'s custom rule is the concrete case behind "a second
 * default pack shows a form error, not a 500": `packs_default_sell_uq`
 * is a partial unique index (one default-sell pack per SKU), and an
 * uncaught violation of it would otherwise surface as a raw
 * UniqueConstraintViolationException. Validating it here, before the
 * INSERT/UPDATE is ever attempted, turns that into an ordinary inline
 * field error — the same reasoning as `unique()` on SkuResource's own
 * `sku_code` field, just for a rule Filament has no built-in helper for.
 */
class PacksRelationManager extends RelationManager
{
    protected static string $relationship = 'packs';

    protected static ?string $recordTitleAttribute = 'label';

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Unique per SKU (packs_sku_code_uq), e.g. EACH, INNER6, OUTER24.'),
                    TextInput::make('label')
                        ->required()
                        ->maxLength(255),
                    Select::make('pack_level')
                        ->options([
                            'each' => 'Each',
                            'inner' => 'Inner',
                            'outer' => 'Outer',
                            'pallet' => 'Pallet',
                        ])
                        ->default('each')
                        ->required(),
                    TextInput::make('base_units')
                        ->label('Base units')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->helperText('How many base units this pack contains. Unique per SKU (packs_sku_units_uq) — a pack is a transaction unit, never a storage unit.'),
                    TextInput::make('barcode')->maxLength(255),
                    TextInput::make('gross_weight_g')->numeric()->minValue(0),
                    TextInput::make('length_mm')->numeric()->minValue(0),
                    TextInput::make('width_mm')->numeric()->minValue(0),
                    TextInput::make('height_mm')->numeric()->minValue(0),
                    TextInput::make('packs_per_layer')->numeric()->integer()->minValue(1),
                    TextInput::make('layers_per_pallet')->numeric()->integer()->minValue(1),
                    Toggle::make('is_sellable')->default(true),
                    Toggle::make('is_default_sell')
                        ->label('Default sell pack')
                        ->helperText('Exactly one pack per SKU may be the default.')
                        ->rule(function (Field $component) {
                            return function (string $attribute, mixed $value, Closure $fail) use ($component) {
                                if (! $value) {
                                    return;
                                }

                                $skuId = $this->getOwnerRecord()->getKey();
                                $editingPack = $component->getRecord();

                                $query = Pack::query()
                                    ->where('sku_id', $skuId)
                                    ->where('is_default_sell', true);

                                if ($editingPack !== null) {
                                    $query->whereKeyNot($editingPack->getKey());
                                }

                                $alreadyDefault = $query->exists();

                                if ($alreadyDefault) {
                                    $fail('Another pack on this SKU is already the default sell pack. Unset that one first.');
                                }
                            };
                        }),
                ])
                ->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('label')->searchable(),
                TextColumn::make('pack_level')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'each' => 'gray',
                        'inner' => 'info',
                        'outer' => 'warning',
                        'pallet' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('base_units')->sortable(),
                IconColumn::make('is_sellable')->boolean(),
                IconColumn::make('is_default_sell')->label('Default')->boolean(),
            ])
            ->defaultSort('base_units')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
