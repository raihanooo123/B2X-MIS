<?php

namespace App\Domain\Warehouse;

use App\Domain\Ordering\PaymentMethod;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\Order;
use App\Models\StockAllocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rules picking and dispatch share.
 *
 * **Which orders the warehouse may work.** An order in `confirmed`,
 * `picking` or `part_dispatched`. A prepaid order (card, BACS, prepay) is
 * paid before it leaves (PaymentMethod: "paid before dispatch"), so it is
 * not released to the warehouse until `payment_status = 'paid'`. On-account
 * orders are released on confirmation.
 *
 * **What a shipment covers.** `stock_allocations` carries no shipment
 * column, and shipment_lines are written only at dispatch (04 §7.2), so a
 * shipment's scope is derived: every active (`allocated` or `picked`)
 * allocation of its order at its location. There is at most one open
 * shipment per order and location (PickListGenerator::open()), so the
 * scope is unambiguous. Stock allocated later — a short pick re-planned,
 * a backorder filled — joins the open shipment if it is at that location,
 * or the next one.
 */
final class FulfilmentRules
{
    public const WORKABLE_ORDER_STATUSES = ['confirmed', 'picking', 'part_dispatched'];

    public const OPEN_SHIPMENT_STATUSES = ['pending', 'picking', 'picked', 'packed'];

    public const ACTIVE_ALLOCATION_STATUSES = ['allocated', 'picked'];

    /**
     * @throws FulfilmentRejectedException
     */
    public static function assertWorkable(Order $order): void
    {
        if (! in_array($order->status, self::WORKABLE_ORDER_STATUSES, true)) {
            throw new FulfilmentRejectedException('order_not_workable', "Order {$order->order_number} is {$order->status}; it cannot be picked or dispatched.", 'order', ['status' => $order->status], 409);
        }

        $method = $order->payment_method === null ? null : PaymentMethod::tryFrom($order->payment_method);
        if ($method !== null && $method->isPrepayment() && $order->payment_status !== 'paid') {
            throw new FulfilmentRejectedException('awaiting_payment', "Order {$order->order_number} is paid before dispatch and has not been paid yet.", 'order', ['payment_status' => $order->payment_status]);
        }
    }

    /**
     * The shipment's scope: its order's active allocations at its location.
     *
     * @return Builder<StockAllocation>
     */
    public static function activeAllocations(int $orderId, int $locationId): Builder
    {
        return StockAllocation::query()
            ->select('stock_allocations.*')
            ->join('order_lines', 'order_lines.id', '=', 'stock_allocations.order_line_id')
            ->where('order_lines.order_id', $orderId)
            ->where('stock_allocations.location_id', $locationId)
            ->whereIn('stock_allocations.status', self::ACTIVE_ALLOCATION_STATUSES);
    }
}
