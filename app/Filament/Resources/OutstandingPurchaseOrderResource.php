<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OutstandingPurchaseOrderResource\Pages;
use App\Filament\Support\PurchasingStatus;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutstandingPurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrderLine::class;

    protected static ?string $slug = 'outstanding-purchase-orders';

    protected static ?string $navigationLabel = 'Outstanding POs';

    protected static ?string $pluralModelLabel = 'Outstanding POs';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 20;

    /** @return Builder<PurchaseOrderLine> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereColumn('received_base_qty', '<', 'base_qty')
            ->whereHas('purchaseOrder', fn (Builder $query) => $query->whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES))
            ->with(['purchaseOrder.supplier', 'purchaseOrder.location']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('purchaseOrder.po_number')
                    ->label('PO')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->url(fn (PurchaseOrderLine $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrder.supplier.name')
                    ->label('Supplier')
                    ->searchable(),
                TextColumn::make('sku_code_snapshot')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('purchaseOrder.location.code')
                    ->label('Receive at')
                    ->toggleable(),
                TextColumn::make('base_qty')
                    ->label('Ordered')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('received_base_qty')
                    ->label('Received')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('units_due')
                    ->label('Still to come')
                    ->state(fn (PurchaseOrderLine $record): int => $record->base_qty - $record->received_base_qty)
                    ->numeric()
                    ->weight('semibold')
                    ->alignEnd(),
                TextColumn::make('expected_date')
                    ->label('Expected')
                    ->state(fn (PurchaseOrderLine $record) => $record->expected_at ?? $record->purchaseOrder?->expected_at)
                    ->date('j M Y')
                    ->placeholder('Not set')
                    ->color(fn (PurchaseOrderLine $record): ?string => self::isLate($record) ? 'danger' : null)
                    ->description(fn (PurchaseOrderLine $record): ?string => self::isLate($record) ? 'Late' : null),
                TextColumn::make('purchaseOrder.status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PurchasingStatus::label($state))
                    ->color(fn (string $state): string => PurchasingStatus::color($state)),
            ])
            ->filters([
                SelectFilter::make('location')->label('Receive at')->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('location_id', $id)))),
                SelectFilter::make('supplier')->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('supplier_id', $id)))),
            ])
            ->actions([
                Action::make('viewPo')->label('View PO')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (PurchaseOrderLine $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrder])),
            ])
            ->striped()
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Nothing due in')
            ->emptyStateDescription('Lines appear here once a supplier confirms an order, and leave when they are fully received.');
    }

    /** The expected date has passed and units are still to come. */
    private static function isLate(PurchaseOrderLine $record): bool
    {
        $expected = $record->expected_at ?? $record->purchaseOrder?->expected_at;

        return $expected !== null && $expected->isBefore(today());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOutstandingPurchaseOrders::route('/')];
    }
}
