<?php

namespace App\Domain\Warehouse;

use App\Domain\Ordering\PaymentMethod;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\CollectionBooking;
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
 * orders are released on confirmation. Pay at collection is not paid before
 * dispatch: it may be picked and staged unpaid, and only its handover waits
 * for the cash (05.6 §7A.6, assertHandoverAllowed()).
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
     * 05.6 §7A.6 step 4 — the handover of a collection is its dispatch, and
     * is refused unless the order is paid: `payment_status = 'paid'`, card
     * or cash alike. An on-account trade collection is the exception: its
     * credit was taken at placement and it is invoiced on dispatch, as any
     * on-account order (05.6 §7A.2 step 4: "on-account … as today"). The
     * booking must still be live, and the staff member is recorded.
     *
     * @throws FulfilmentRejectedException
     */
    public static function assertHandoverAllowed(Order $order, ?CollectionBooking $booking, ?int $staffUserId): void
    {
        if ($booking === null || $booking->status !== 'booked') {
            throw new FulfilmentRejectedException('booking_not_live', "Order {$order->order_number} has no live collection booking.", 'order', ['booking_status' => $booking?->status], 409);
        }
        $paid = $order->payment_status === 'paid'
            || ($order->payment_method === PaymentMethod::OnAccount->value && $order->payment_status === 'on_account');
        if (! $paid) {
            throw new FulfilmentRejectedException('awaiting_payment', "Order {$order->order_number} must be paid before it is handed over.", 'order', ['payment_status' => $order->payment_status]);
        }
        if ($staffUserId === null) {
            throw new FulfilmentRejectedException('staff_required', 'A handover is recorded against the staff member making it.', null, [], 403);
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
