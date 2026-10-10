<?php

namespace App\Filament\Resources;

use App\Domain\Purchasing\ReorderSuggestionService;
use App\Filament\Resources\ReorderSuggestionResource\Pages;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Location;
use App\Models\ReorderSuggestion;
use App\Models\Supplier;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** 05.7 §10 — advisory; purchasing reviews and edits before raising a PO. */
class ReorderSuggestionResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = ReorderSuggestion::class;

    protected static ?string $slug = 'reorder-suggestions';

    protected static ?string $navigationLabel = 'Reorder suggestions';

    protected static ?string $pluralModelLabel = 'Reorder suggestions';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 30;

    /** @return Builder<ReorderSuggestion> */
    public static function getEloquentQuery(): Builder
    {
        return app(ReorderSuggestionService::class)->query()->with(['sku.product', 'location', 'supplier']);
    }

    public static function table(Table $table): Table
    {
        // The decision (what to buy, and why) leads; the figures behind it
        // follow, and the least-used are hidden by default but toggleable.
        return $table
            ->columns([
                TextColumn::make('sku.sku_code')
                    ->label('SKU')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->description(fn (ReorderSuggestion $record): ?string => $record->sku?->product?->name)
                    ->searchable(),
                TextColumn::make('suggested_base_qty')
                    ->label('Suggested units')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary')
                    ->description(fn (ReorderSuggestion $record): string => $record->pack_base_units > 1
                        ? intdiv($record->suggested_base_qty, $record->pack_base_units)." packs of {$record->pack_base_units}"
                        : ''),
                TextColumn::make('trigger')
                    ->label('Why')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Below reorder point' ? 'danger' : 'warning')
                    ->state(fn (ReorderSuggestion $record): array => array_values(array_filter([
                        $record->below_reorder_point ? 'Below reorder point' : null,
                        $record->cover_short ? 'Will run short' : null,
                    ]))),
                TextColumn::make('available_base_qty')->label('Available')->numeric()->alignEnd()->sortable(),
                TextColumn::make('incoming_base_qty')->label('On order')->numeric()->alignEnd()->sortable(),
                TextColumn::make('sold_base_qty')->label('Sold')->numeric()->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (ReorderSuggestion $record): string => "last {$record->sales_window_days} days"),
                TextColumn::make('cover_days')->label('Lasts')->suffix(' days')->alignEnd()->sortable()->placeholder('No sales'),
                TextColumn::make('supplier.name')->label('Last supplier')->placeholder('No PO yet')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location.name')->label('Location')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reorder_point_base_qty')->label('Reorder point')->numeric()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lead_time_days')->label('Lead time')->suffix(' days')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('location_id')->label('Location')
                    ->options(fn (): array => Location::query()->where('is_sellable', true)->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('supplier_id')->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->actions([
                ReorderSettingResource::editAction(),
            ])
            ->striped()
            ->defaultSort('suggested_base_qty', 'desc')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Nothing to reorder')
            ->emptyStateDescription('Stock is above every reorder point, and recent sales will not run anything short before new stock could arrive.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReorderSuggestions::route('/')];
    }
}
