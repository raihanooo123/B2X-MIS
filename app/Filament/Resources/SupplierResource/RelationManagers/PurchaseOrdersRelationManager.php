<?php

namespace App\Filament\Resources\SupplierResource\RelationManagers;

use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\SupplierResource\Pages\ViewSupplier;
use App\Filament\Support\MoneyFormatter;
use App\Filament\Support\PurchasingStatus;
use App\Models\PurchaseOrder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The supplier's purchase orders, newest first, on the supplier's detail
 * page. Read-only: orders are raised and changed on the purchase order
 * screens, behind PurchaseOrderPolicy.
 */
class PurchaseOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrders';

    protected static ?string $title = 'Purchase orders';

    protected static ?string $icon = 'heroicon-o-document-text';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewSupplier::class && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('po_number')
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->columns([
                TextColumn::make('po_number')
                    ->label('PO')
                    ->weight('medium')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('location.name')
                    ->label('Receive at'),
                TextColumn::make('expected_at')
                    ->label('Expected')
                    ->date('j M Y')
                    ->placeholder('Not set')
                    ->sortable(),
                TextColumn::make('goods_total_minor')
                    ->label('Goods total')
                    ->formatStateUsing(fn (int $state): ?string => MoneyFormatter::minor($state))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PurchasingStatus::label($state))
                    ->color(fn (string $state): string => PurchasingStatus::color($state)),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('No purchase orders with this supplier yet');
    }
}
