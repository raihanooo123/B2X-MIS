<?php
namespace App\Domain\Credit;
use App\Models\CollectionBooking;
use App\Models\CollectionSlot;
use App\Models\Company;
use App\Models\CreditHold;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderApprovalRequest;
use App\Models\Payment;
use App\Models\StockAllocation;
use App\Models\StockLevel;

/** Company -> slot -> sorted stock -> payments/invoices -> aggregate rows. */
final class CreditOrderLocks
{
    /** @return array{company: Company, order: Order, slot: ?CollectionSlot, booking: ?CollectionBooking} */
    public function lock(int $orderId): array
    {
        $source = Order::query()->findOrFail($orderId, ['id','company_id']);
        if ($source->company_id === null) { throw new CreditRefused('not_trade_order', 'This is not a trade order.'); }
        $company = Company::query()->where('id', $source->company_id)->lockForUpdate()->firstOrFail();
        $slotId = CollectionBooking::query()->where('order_id', $orderId)->value('collection_slot_id');
        $slot = $slotId === null ? null : CollectionSlot::query()->where('id', $slotId)->lockForUpdate()->firstOrFail();
        $identities = StockAllocation::query()->join('order_lines', 'order_lines.id', '=', 'stock_allocations.order_line_id')
            ->where('order_lines.order_id', $orderId)->whereIn('stock_allocations.status', ['allocated','picked'])
            ->orderBy('stock_allocations.sku_id')->orderBy('stock_allocations.location_id')->orderByRaw('stock_allocations.batch_id ASC NULLS FIRST')
            ->get(['stock_allocations.sku_id','stock_allocations.location_id','stock_allocations.batch_id']);
        foreach ($identities as $identity) {
            StockLevel::identity($identity->sku_id, $identity->location_id, $identity->batch_id)->lockForUpdate()->firstOrFail();
        }
        Payment::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        Invoice::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->firstOrFail();
        OrderApprovalRequest::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        CreditHold::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        $booking = CollectionBooking::query()->where('order_id', $orderId)->lockForUpdate()->first();
        return compact('company','order','slot','booking');
    }
}
