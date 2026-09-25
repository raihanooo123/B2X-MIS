<?php

namespace App\Http\Controllers\Warehouse;

use App\Domain\Warehouse\FulfilmentRules;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * The two queues the warehouse pages open on (05.5 U10): orders ready to
 * pick — workable, released for payment, with stock allocated at a
 * location — and shipments picked and waiting to leave.
 */
final class FulfilmentQueue
{
    private const SHOWN = 100;

    /**
     * @return list<array{order_number: string, location_code: string, lines: int, base_qty: int, fulfilment_type: string, open_shipment_id: ?string, placed_at: ?string}>
     */
    public static function toPick(): array
    {
        $rows = DB::table('stock_allocations')
            ->join('order_lines', 'order_lines.id', '=', 'stock_allocations.order_line_id')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('locations', 'locations.id', '=', 'stock_allocations.location_id')
            ->whereIn('stock_allocations.status', ['allocated'])
            ->whereIn('orders.status', FulfilmentRules::WORKABLE_ORDER_STATUSES)
            ->where(fn ($q) => $q->whereNull('orders.payment_method')->orWhere('orders.payment_method', 'on_account')->orWhere('orders.payment_status', 'paid'))
            ->groupBy('orders.id', 'orders.order_number', 'orders.fulfilment_type', 'orders.placed_at', 'locations.id', 'locations.code')
            ->orderBy('orders.placed_at')
            ->orderBy('orders.id')
            ->limit(self::SHOWN)
            ->selectRaw('orders.id AS order_id, orders.order_number, orders.fulfilment_type, orders.placed_at, locations.id AS location_id, locations.code AS location_code, COUNT(DISTINCT order_lines.id) AS lines, SUM(stock_allocations.base_qty) AS base_qty')
            ->get();

        $open = Shipment::query()
            ->whereIn('order_id', $rows->pluck('order_id'))
            ->whereIn('status', FulfilmentRules::OPEN_SHIPMENT_STATUSES)
            ->get(['public_id', 'order_id', 'location_id'])
            ->keyBy(fn (Shipment $s) => $s->order_id.':'.$s->location_id);

        return array_values($rows->map(fn ($r) => [
            'order_number' => (string) $r->order_number,
            'location_code' => (string) $r->location_code,
            'lines' => (int) $r->lines,
            'base_qty' => (int) $r->base_qty,
            'fulfilment_type' => (string) $r->fulfilment_type,
            'open_shipment_id' => $open->get($r->order_id.':'.$r->location_id)?->public_id,
            'placed_at' => $r->placed_at === null ? null : (string) $r->placed_at,
        ])->all());
    }

    /**
     * @return list<array{id: string, order_number: string, status: string, fulfilment_type: string, location_code: ?string, picked_at: ?string}>
     */
    public static function toDispatch(): array
    {
        return array_values(Shipment::query()
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->leftJoin('locations', 'locations.id', '=', 'shipments.location_id')
            ->whereIn('shipments.status', ['picked', 'packed'])
            ->orderBy('shipments.picked_at')
            ->orderBy('shipments.id')
            ->limit(self::SHOWN)
            ->get(['shipments.public_id', 'shipments.status', 'shipments.fulfilment_type', 'shipments.picked_at', 'orders.order_number', 'locations.code as location_code'])
            ->map(fn (Shipment $s) => [
                'id' => $s->public_id,
                'order_number' => (string) $s->getAttribute('order_number'),
                'status' => $s->status,
                'fulfilment_type' => $s->fulfilment_type,
                'location_code' => $s->getAttribute('location_code') === null ? null : (string) $s->getAttribute('location_code'),
                'picked_at' => $s->picked_at?->toIso8601String(),
            ])
            ->all());
    }
}
