<?php

namespace App\Filament\Resources\StocktakeResource\RelationManagers;

use App\Domain\Warehouse\StocktakeReason;
use App\Filament\Resources\StocktakeResource;
use App\Models\StocktakeLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What was counted, what was expected at the moment of the count, the
 * variance and its reason, in base units. Read-only: a posted stocktake is
 * the record behind its stock movements. Shown to whoever may view the
 * stocktake (StocktakePolicy).
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    protected static ?string $icon = 'heroicon-o-queue-list';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return StocktakeResource::canView($ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['sku:id,sku_code', 'batch:id,batch_code', 'serials']))
            ->columns([
                TextColumn::make('sku.sku_code')->label('SKU')->fontFamily('mono')->weight('medium')
                    ->description(fn (StocktakeLine $record): ?string => $record->batch === null ? null : 'Batch '.$record->batch->batch_code)
                    ->searchable(),
                TextColumn::make('counted_base_qty')->label('Counted')->numeric()->alignEnd(),
                TextColumn::make('expected_base_qty')->label('Expected')->numeric()->alignEnd()->placeholder('Not posted'),
                TextColumn::make('variance_base_qty')->label('Variance')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : sprintf('%+d', $state))
                    ->color(fn (?int $state): string => $state === null || $state === 0 ? 'gray' : ($state > 0 ? 'success' : 'danger'))
                    ->weight('semibold'),
                TextColumn::make('reason_code')->label('Reason')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : (StocktakeReason::tryFrom($state)?->label() ?? $state)),
                TextColumn::make('counted_at')->label('Counted at')->dateTime()->toggleable(),
                TextColumn::make('serials')->label('Serials scanned')->placeholder('—')->wrap()
                    ->state(fn (StocktakeLine $record): ?string => $record->serials->sortBy('serial_number')->pluck('serial_number')->implode(', ') ?: null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('variance')->label('With a variance only')
                    ->query(fn (Builder $query): Builder => $query->where('variance_base_qty', '<>', 0)),
            ])
            ->defaultSort('counted_at')
            ->emptyStateHeading('Nothing counted');
    }
}
