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

/**
 * 05.2 §18.2, 02 §31.1 — the one lock order every writer of an existing
 * trade order's credit, approval or expiry uses (decision, reaper, payment):
 *
 *   companies → orders → collection_slots (when booked) → stock_levels
 *   ascending (sku, location, batch NULLS FIRST) → payments → invoices →
 *   order_approval_requests → credit_holds → collection_bookings.
 *
 * The order row comes straight after `companies`, as in every other path
 * that changes an existing order (DispatchService, OrderCancellationService,
 * PartialCancellations, CashAtCollection, CollectionExpiry): taking it
 * after stock_levels would deadlock against them on the same order.
 *
 * Two phases, so a caller that reserves stock for an order (an approved
 * credit shortfall, paying in advance) does it between them: head()
 * holds companies → orders → collection_slots, the caller allocates
 * (stock_levels), then tail() takes the rest. Re-locking a row this
 * transaction already holds is free.
 */
final class CreditOrderLocks
{
    /** @return array{company: Company, order: Order, slot: ?CollectionSlot, booking: ?CollectionBooking} */
    public function lock(int $orderId): array
    {
        $head = $this->head($orderId);

        return $head + ['booking' => $this->tail($orderId)];
    }

    /** @return array{company: Company, order: Order, slot: ?CollectionSlot} */
    public function head(int $orderId): array
    {
        $source = Order::query()->findOrFail($orderId, ['id', 'company_id']);
        if ($source->company_id === null) {
            throw new CreditRefused('not_trade_order', 'This is not a trade order.', 404);
        }

        $company = Company::query()->where('id', $source->company_id)->lockForUpdate()->firstOrFail();
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->firstOrFail();

        // The booking cannot move slot without the order lock, held now.
        $slotId = CollectionBooking::query()->where('order_id', $orderId)->value('collection_slot_id');
        $slot = $slotId === null ? null : CollectionSlot::query()->where('id', $slotId)->lockForUpdate()->firstOrFail();

        return ['company' => $company, 'order' => $order, 'slot' => $slot];
    }

    /** After head(): stock_levels → payments → invoices → requests → holds → booking. */
    public function tail(int $orderId): ?CollectionBooking
    {
        $identities = StockAllocation::query()
            ->join('order_lines', 'order_lines.id', '=', 'stock_allocations.order_line_id')
            ->where('order_lines.order_id', $orderId)
            ->whereIn('stock_allocations.status', ['allocated', 'picked'])
            ->distinct()
            ->orderBy('stock_allocations.sku_id')
            ->orderBy('stock_allocations.location_id')
            ->orderByRaw('stock_allocations.batch_id ASC NULLS FIRST')
            ->get(['stock_allocations.sku_id', 'stock_allocations.location_id', 'stock_allocations.batch_id']);
        foreach ($identities as $identity) {
            StockLevel::identity((int) $identity->sku_id, (int) $identity->location_id, $identity->batch_id === null ? null : (int) $identity->batch_id)
                ->lockForUpdate()->firstOrFail();
        }

        Payment::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get(['id']);
        Invoice::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get(['id']);
        OrderApprovalRequest::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get(['id']);
        CreditHold::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get(['id']);

        return CollectionBooking::query()->where('order_id', $orderId)->lockForUpdate()->first();
    }
}
