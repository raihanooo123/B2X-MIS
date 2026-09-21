<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Filament\Resources\SkuResource;
use App\Models\Sku;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Part 2's "ProductResource with a relation manager for Sku." Shares
 * SkuResource::coreFormSections() rather than duplicating the Sku form
 * — the only field that section omits is `product_id`, which this
 * relation manager doesn't need: the parent product is already implied.
 */
class SkusRelationManager extends RelationManager
{
    protected static string $relationship = 'skus';

    protected static ?string $recordTitleAttribute = 'sku_code';

    public function form(Form $form): Form
    {
        return $form->schema(SkuResource::coreFormSections());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku_code')
            ->columns([
                TextColumn::make('sku_code')->searchable()->sortable(),
                TextColumn::make('variant_label')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'gray',
                        'coming_soon' => 'info',
                        'discontinued' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_stock_tracked')
                    ->label('Tracked')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sku_code')
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                Action::make('openFull')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Sku $record): string => SkuResource::getUrl('edit', ['record' => $record]))
                    ->tooltip('Full editing, including packs, on the standalone SKU page.'),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
