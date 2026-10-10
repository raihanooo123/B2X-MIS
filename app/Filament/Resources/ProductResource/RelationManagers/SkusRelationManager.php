<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Filament\Resources\SkuResource;
use App\Filament\Support\CatalogueStatus;
use App\Models\Sku;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    protected static ?string $title = 'SKUs';

    protected static ?string $icon = 'heroicon-o-tag';

    public function form(Form $form): Form
    {
        return $form->schema(SkuResource::coreFormSections());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku_code')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('packs'))
            ->columns([
                TextColumn::make('sku_code')
                    ->label('SKU')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant_label')
                    ->label('Variant')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('packs_count')
                    ->label('Packs')
                    ->counts('packs')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogueStatus::label($state))
                    ->color(fn (string $state): string => CatalogueStatus::color($state)),
                IconColumn::make('is_stock_tracked')
                    ->label('Tracked')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sku_code')
            ->headerActions([
                CreateAction::make()->label('Add SKU')->icon('heroicon-m-plus'),
            ])
            ->actions([
                Action::make('openFull')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Sku $record): string => SkuResource::getUrl('view', ['record' => $record]))
                    ->tooltip('Full detail, including packs, on the standalone SKU page.'),
                EditAction::make(),
                // No delete action — nobody gets delete (SkuPolicy
                // denies it unconditionally); archiving via `status`
                // comes later.
            ])
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateHeading('No SKUs yet')
            ->emptyStateDescription('Add the first SKU, then its packs on the SKU page.');
    }
}
