<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OutstandingPurchaseOrderResource\Pages;
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
        return $table->columns([
            TextColumn::make('purchaseOrder.po_number')->label('PO')->searchable()->sortable(),
            TextColumn::make('purchaseOrder.supplier.name')->label('Supplier')->searchable(),
            TextColumn::make('sku_code_snapshot')->label('SKU')->searchable(),
            TextColumn::make('purchaseOrder.location.code')->label('Receive at'),
            TextColumn::make('purchaseOrder.status')->label('Status')->badge(),
            TextColumn::make('base_qty')->label('Ordered units')->alignEnd(),
            TextColumn::make('received_base_qty')->label('Received units')->alignEnd(),
            TextColumn::make('units_due')->label('Units due')->state(fn (PurchaseOrderLine $record): int => $record->base_qty - $record->received_base_qty)->alignEnd(),
            TextColumn::make('expected_date')->label('Expected')->state(fn (PurchaseOrderLine $record) => $record->expected_at ?? $record->purchaseOrder?->expected_at)->date()->placeholder('Not set'),
        ])->filters([
            SelectFilter::make('location')->label('Receive at')->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('location_id', $id)))),
            SelectFilter::make('supplier')->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $id) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('supplier_id', $id)))),
            SelectFilter::make('status')->options([
                'confirmed' => 'Confirmed',
                'in_production' => 'In production',
                'shipped' => 'Shipped',
                'part_received' => 'Part received',
            ])->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $status) => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('status', $status)))),
        ])->actions([
            Action::make('viewPo')->label('View PO')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (PurchaseOrderLine $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrder])),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOutstandingPurchaseOrders::route('/')];
    }
}
