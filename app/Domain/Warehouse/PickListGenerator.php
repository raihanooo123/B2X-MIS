<?php

namespace App\Domain\Warehouse;

use App\Domain\Catalogue\Thumbnails;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StockSerial;
use Illuminate\Support\Facades\DB;

/**
 * Pick lists — 05.5 §5.1–5.2.
 *
 * **Per shipment, not per order.** open() starts (or returns) the one open
 * shipment for an order at a location; generate() lists what it covers:
 * the order's active allocations at that location (FulfilmentRules). One
 * order can ship in several shipments on different days; each has its own
 * list.
 *
 * **Walk order.** `bins.walk_sequence` of the allocation's
 * `suggested_bin_id` (advisory, 04 §4.7), then SKU code; allocations with
 * no suggested bin or an unsequenced bin come last. Ties break on expiry,
 * then allocation id, so the order is deterministic.
 *
 * **Exactly what was allocated.** Each line names its batch (with expiry)
 * and, for serial-tracked SKUs, the serials reserved to its order line at
 * that location and batch — the units the picker must scan (04 §6.1).
 */
final class PickListGenerator
{
    public function __construct(
        private readonly Thumbnails $thumbnails = new Thumbnails,
    ) {}

    /**
     * The open shipment for this order at this location, created if there
     * is none. Serialised on the order row, so two pickers starting the
     * same order get the same shipment.
     *
     * @throws FulfilmentRejectedException
     */
    public function open(int $orderId, int $locationId): Shipment
    {
        return (new DeadlockRetryPolicy)->run(fn () => DB::transaction(function () use ($orderId, $locationId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            FulfilmentRules::assertWorkable($order);

            $open = Shipment::query()
                ->where('order_id', $order->id)
                ->where('location_id', $locationId)
                ->whereIn('status', FulfilmentRules::OPEN_SHIPMENT_STATUSES)
                ->first();
            if ($open !== null) {
                return $open;
            }

            if (! FulfilmentRules::activeAllocations($order->id, $locationId)->exists()) {
                throw new FulfilmentRejectedException('nothing_to_pick', "Order {$order->order_number} has no stock allocated at this location to pick.", 'order');
            }

            $shipment = Shipment::create([
                'order_id' => $order->id,
                'location_id' => $locationId,
                'fulfilment_type' => $order->fulfilment_type,
                'status' => 'pending',
            ]);

            if ($order->status === 'confirmed') {
                $order->forceFill(['status' => 'picking'])->save();
            }

            return $shipment;
        }), self::class);
    }

    public function generate(Shipment $shipment): PickList
    {
        $order = Order::query()->findOrFail($shipment->order_id);

        $rows = FulfilmentRules::activeAllocations($order->id, $shipment->location_id)
            ->join('skus', 'skus.id', '=', 'stock_allocations.sku_id')
            ->leftJoin('bins', 'bins.id', '=', 'stock_allocations.suggested_bin_id')
            ->leftJoin('batches', 'batches.id', '=', 'stock_allocations.batch_id')
            ->orderByRaw('bins.walk_sequence ASC NULLS LAST')
            ->orderBy('skus.sku_code')
            ->orderByRaw('batches.expires_on ASC NULLS LAST')
            ->orderBy('stock_allocations.id')
            // addSelect, not get([...]): activeAllocations() already selects
            // stock_allocations.*, and get()'s columns are ignored once a
            // select is set.
            ->addSelect([
                'order_lines.line_no',
                'order_lines.name_snapshot',
                'order_lines.pack_label_snapshot',
                'order_lines.pack_base_units',
                'skus.sku_code',
                'skus.product_id',
                'skus.tracking_mode',
                'batches.batch_code',
                'batches.expires_on',
                'bins.code as bin_code',
                'bins.walk_sequence',
            ])
            ->get();

        $serials = StockSerial::query()
            ->whereIn('order_line_id', $rows->pluck('order_line_id')->unique()->values()->all())
            ->where('location_id', $shipment->location_id)
            ->whereIn('status', ['allocated', 'picked'])
            ->orderBy('serial_number')
            ->get(['id', 'order_line_id', 'batch_id', 'serial_number', 'status']);

        $skuIds = array_values(array_unique(array_map('intval', $rows->pluck('sku_id')->all())));
        $productIds = array_values(array_unique(array_map('intval', $rows->pluck('product_id')->all())));
        $thumbs = $this->thumbnails->lookup($skuIds, $productIds);

        $lines = [];
        foreach ($rows as $row) {
            $lineSerials = array_values($serials
                ->filter(fn (StockSerial $s) => $s->order_line_id === $row->order_line_id && $s->batch_id === $row->batch_id)
                ->map(fn (StockSerial $s) => ['id' => $s->id, 'serial_number' => $s->serial_number, 'picked' => $s->status === 'picked'])
                ->all());

            $lines[] = new PickLine(
                allocationId: $row->id,
                orderLineId: $row->order_line_id,
                lineNo: (int) $row->getAttribute('line_no'),
                skuId: $row->sku_id,
                skuCode: (string) $row->getAttribute('sku_code'),
                name: (string) $row->getAttribute('name_snapshot'),
                thumbnailUrl: Thumbnails::pick($thumbs, $row->sku_id, (int) $row->getAttribute('product_id')),
                packLabel: (string) $row->getAttribute('pack_label_snapshot'),
                packBaseUnits: max(1, (int) $row->getAttribute('pack_base_units')),
                baseQty: $row->base_qty,
                trackingMode: (string) $row->getAttribute('tracking_mode'),
                batchId: $row->batch_id,
                batchCode: $row->getAttribute('batch_code') === null ? null : (string) $row->getAttribute('batch_code'),
                expiresOn: $row->getAttribute('expires_on') === null ? null : substr((string) $row->getAttribute('expires_on'), 0, 10),
                binCode: $row->getAttribute('bin_code') === null ? null : (string) $row->getAttribute('bin_code'),
                walkSequence: $row->getAttribute('walk_sequence') === null ? null : (int) $row->getAttribute('walk_sequence'),
                status: $row->status,
                serials: $lineSerials,
            );
        }

        return new PickList($shipment, $order, $lines);
    }
}
