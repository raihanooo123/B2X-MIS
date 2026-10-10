<?php

namespace App\Filament\Resources\OutstandingPurchaseOrderResource\Pages;

use App\Filament\Resources\OutstandingPurchaseOrderResource;
use App\Filament\Support\PurchasingStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOutstandingPurchaseOrders extends ListRecords
{
    protected static string $resource = OutstandingPurchaseOrderResource::class;

    public function getSubheading(): string
    {
        return 'Goods confirmed by suppliers and not yet fully received, line by line. Quantities are in base units.';
    }

    /** One tab per stage the order has reached, counted in one query. */
    public function getTabs(): array
    {
        $counts = PurchaseOrderLine::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereColumn('purchase_order_lines.received_base_qty', '<', 'purchase_order_lines.base_qty')
            ->whereIn('purchase_orders.status', PurchaseOrder::RECEIVABLE_STATUSES)
            ->groupBy('purchase_orders.status')
            ->selectRaw('purchase_orders.status AS po_status, COUNT(*) AS n')
            ->pluck('n', 'po_status');

        $tabs = ['all' => Tab::make('All')->badge((int) $counts->sum())];

        foreach (PurchaseOrder::RECEIVABLE_STATUSES as $status) {
            $tabs[$status] = Tab::make(PurchasingStatus::label($status))
                ->badge((int) ($counts[$status] ?? 0))
                ->badgeColor(PurchasingStatus::color($status))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereHas('purchaseOrder', fn (Builder $po) => $po->where('status', $status)));
        }

        return $tabs;
    }
}
