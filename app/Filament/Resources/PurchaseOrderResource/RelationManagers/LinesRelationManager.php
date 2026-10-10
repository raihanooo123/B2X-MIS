<?php

namespace App\Filament\Resources\PurchaseOrderResource\RelationManagers;

use App\Filament\Resources\PurchaseOrderResource\Pages\ViewPurchaseOrder;
use App\Filament\Resources\SkuResource;
use App\Filament\Support\MoneyFormatter;
use App\Models\PurchaseOrderLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A purchase order's lines as a table on its detail page. Read-only:
 * lines are changed through the draft's edit form (PurchaseOrderService),
 * and received through Goods in. Quantities are base units; the unit price
 * keeps its four decimal places.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    protected static ?string $icon = 'heroicon-o-queue-list';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewPurchaseOrder::class && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['pack', 'sku.product']))
            ->columns([
                TextColumn::make('line_no')
                    ->label('#')
                    ->color('gray'),
                TextColumn::make('sku_code_snapshot')
                    ->label('SKU')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->description(fn (PurchaseOrderLine $record): ?string => $record->sku?->product?->name)
                    ->url(fn (PurchaseOrderLine $record): string => SkuResource::getUrl('view', ['record' => $record->sku_id]))
                    ->searchable(),
                TextColumn::make('pack.label')
                    ->label('Pack')
                    ->description(fn (PurchaseOrderLine $record): string => "{$record->pack_base_units} units each"),
                TextColumn::make('pack_qty')
                    ->label('Packs')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('base_qty')
                    ->label('Units ordered')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('received_base_qty')
                    ->label('Units received')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn (PurchaseOrderLine $record): ?string => match (true) {
                        $record->received_base_qty >= $record->base_qty => 'success',
                        $record->received_base_qty > 0 => 'warning',
                        default => null,
                    }),
                TextColumn::make('unit_fob_e4')
                    ->label('Price per unit')
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::e4($state))
                    ->alignEnd(),
                TextColumn::make('line_fob_minor')
                    ->label('Line total')
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::minor($state))
                    ->weight('medium')
                    ->alignEnd(),
                TextColumn::make('variance_reason')
                    ->label('Variance')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('line_no')
            ->paginated(false)
            ->emptyStateHeading('No lines on this order');
    }
}
