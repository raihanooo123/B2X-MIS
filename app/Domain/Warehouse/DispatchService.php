<?php

namespace App\Domain\Warehouse;

use App\Domain\Billing\InvoiceService;
use App\Domain\Catalogue\TrackingMode;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Notifications\Notifications;
use App\Domain\Warehouse\Events\ShipmentDispatched;
use App\Domain\Warehouse\Exceptions\FulfilmentRejectedException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\ShipmentLineBatch;
use App\Models\ShipmentLineSerial;
use App\Models\Sku;
use App\Models\StockAllocation;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockSerial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch — the atomic moment of 04 §7.2 and 05.5 §7.
 *
 * One transaction, retried whole on deadlock (04 §4.5), locking:
 *
 *   1. orders FOR UPDATE — serialises dispatch against a cancellation of
 *      the same order: exactly one wins (05.5 §13 W2). The order must
 *      still be workable and, if prepaid, paid (FulfilmentRules).
 *   2. shipments FOR UPDATE — a shipment dispatches once. A retry of a
 *      dispatched shipment is answered with it and writes nothing:
 *      idempotent on the shipment (05.5 §10).
 *   3. stock_allocations FOR UPDATE, ascending id — the shipment's scope.
 *      Every line must be picked; an unpicked line blocks dispatch
 *      (05.5 §12). Short-picked lines are already reduced or released.
 *   4. stock_levels, ascending (sku_id, location_id, batch_id NULLS
 *      FIRST) — 02 §11.1. No companies or collection_slots row is
 *      touched: dispatch moves no credit. Invoicing, which does, runs
 *      after commit in its own transaction (companies first).
 *
 * Then, per picked allocation: a `dispatch` movement (−qty, referencing
 * the allocation, which is what the recall trace joins on, 02 §7.5), and
 * on_hand and allocated both down by it in one statement; the allocation
 * `dispatched`; its picked serials `dispatched` with the movement id.
 * shipment_lines per order line, shipment_line_batches per batch,
 * shipment_line_serials per serial; order_lines.dispatched_base_qty up and
 * allocated_base_qty down; orders.status recomputed: `dispatched` when
 * every line is fully dispatched, otherwise `part_dispatched` (05.5 §7.2).
 *
 * After commit, and only once: the customer is told what shipped and what
 * remains (05.12 `shipment.dispatched`), on-account orders are invoiced
 * per `invoicing.mode` (05.5 §7.3), and ShipmentDispatched fires.
 */
final class DispatchService
{
    public function __construct(
        private readonly InvoiceService $invoices = new InvoiceService,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /**
     * @throws FulfilmentRejectedException
     */
    public function dispatch(Shipment $shipment, DispatchDetails $details): DispatchOutcome
    {
        $outcome = (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->dispatchWithinTransaction($shipment->id, $details)),
            self::class,
        );

        if (! $outcome->replayed) {
            $shipmentId = $outcome->shipment->id;
            $event = new ShipmentDispatched($shipmentId, $outcome->shipment->order_id, $outcome->orderFullyDispatched);
            DB::afterCommit(function () use ($shipmentId, $event) {
                $this->notifications->shipmentDispatched($shipmentId);
                $this->invoices->whenDispatched($shipmentId);
                event($event);
            });
        }

        return $outcome;
    }

    private function dispatchWithinTransaction(int $shipmentId, DispatchDetails $details): DispatchOutcome
    {
        $now = CarbonImmutable::now();

        // 1–2. The order, then the shipment.
        $orderId = (int) Shipment::query()->whereKey($shipmentId)->value('order_id');
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

        if ($shipment->status === 'dispatched') {
            return new DispatchOutcome($shipment, true, $order->status === 'dispatched');
        }
        if ($shipment->status === 'cancelled') {
            throw new FulfilmentRejectedException('shipment_cancelled', 'This shipment was cancelled.', null, [], 409);
        }

        FulfilmentRules::assertWorkable($order);

        // 3. The shipment's scope. Every line picked, or nothing leaves.
        $allocations = FulfilmentRules::activeAllocations($order->id, $shipment->location_id)
            ->orderBy('stock_allocations.id')
            ->lock('FOR UPDATE OF stock_allocations')
            ->get();

        $unpicked = $allocations->where('status', 'allocated');
        if ($unpicked->isNotEmpty()) {
            $lineNos = OrderLine::query()->whereIn('id', $unpicked->pluck('order_line_id'))->orderBy('line_no')->pluck('line_no')->all();

            throw new FulfilmentRejectedException('unpicked_lines', 'Every line must be picked, or reported short, before dispatch. Still to pick: line '.implode(', ', $lineNos).'.', 'lines', ['line_nos' => $lineNos]);
        }
        if ($allocations->isEmpty()) {
            throw new FulfilmentRejectedException('nothing_picked', 'Nothing on this shipment is picked to dispatch.', 'lines');
        }

        $this->assertSerialsPicked(array_values($allocations->all()));

        // 4. stock_levels in the global order.
        $identities = $allocations
            ->map(fn (StockAllocation $a) => ['sku_id' => $a->sku_id, 'location_id' => $a->location_id, 'batch_id' => $a->batch_id])
            ->unique(fn (array $i) => $i['sku_id'].':'.$i['location_id'].':'.($i['batch_id'] ?? 'null'))
            ->sortBy(fn (array $i) => [$i['sku_id'], $i['location_id'], $i['batch_id'] ?? -1])
            ->values();
        foreach ($identities as $identity) {
            StockLevel::identity($identity['sku_id'], $identity['location_id'], $identity['batch_id'])->lockForUpdate()->first()
                ?? throw new FulfilmentRejectedException('no_stock_level', 'A picked line has no stock level row; it cannot be dispatched.', null, [], 409);
        }

        /** @var array<int, array{qty: int, sku_id: int, batches: array<int, int>, serials: list<int>}> $byLine */
        $byLine = [];

        foreach ($allocations as $allocation) {
            $movement = StockMovement::create([
                'occurred_at' => $now,
                'sku_id' => $allocation->sku_id,
                'location_id' => $allocation->location_id,
                'batch_id' => $allocation->batch_id,
                'movement_type' => 'dispatch',
                'base_qty' => -$allocation->base_qty,
                'reference_type' => 'allocation',
                'reference_id' => $allocation->id,
                'actor_user_id' => $details->actorUserId,
            ]);

            // The only movement type that moves both fields (04 §3) — together.
            StockLevel::identity($allocation->sku_id, $allocation->location_id, $allocation->batch_id)->update([
                'on_hand_base_qty' => DB::raw('on_hand_base_qty - '.$allocation->base_qty),
                'allocated_base_qty' => DB::raw('allocated_base_qty - '.$allocation->base_qty),
                'version' => DB::raw('version + 1'),
                'last_movement_id' => $movement->id,
                'updated_at' => $now,
            ]);

            $allocation->forceFill(['status' => 'dispatched'])->save();

            $serialIds = $this->serialsOf($allocation)->where('status', 'picked')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($serialIds !== []) {
                StockSerial::query()->whereIn('id', $serialIds)->update([
                    'status' => 'dispatched',
                    'dispatched_movement_id' => $movement->id,
                    'dispatched_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $line = $byLine[$allocation->order_line_id] ??= ['qty' => 0, 'sku_id' => $allocation->sku_id, 'batches' => [], 'serials' => []];
            $line['qty'] += $allocation->base_qty;
            if ($allocation->batch_id !== null) {
                $line['batches'][$allocation->batch_id] = ($line['batches'][$allocation->batch_id] ?? 0) + $allocation->base_qty;
            }
            $line['serials'] = array_merge($line['serials'], $serialIds);
            $byLine[$allocation->order_line_id] = $line;
        }

        ksort($byLine);
        foreach ($byLine as $orderLineId => $line) {
            $shipmentLine = ShipmentLine::create([
                'shipment_id' => $shipment->id,
                'order_line_id' => $orderLineId,
                'sku_id' => $line['sku_id'],
                'dispatched_base_qty' => $line['qty'],
            ]);
            foreach ($line['batches'] as $batchId => $qty) {
                ShipmentLineBatch::create(['shipment_line_id' => $shipmentLine->id, 'batch_id' => $batchId, 'base_qty' => $qty]);
            }
            foreach ($line['serials'] as $serialId) {
                ShipmentLineSerial::create(['shipment_line_id' => $shipmentLine->id, 'serial_id' => $serialId]);
            }

            OrderLine::query()->whereKey($orderLineId)->update([
                'dispatched_base_qty' => DB::raw('dispatched_base_qty + '.$line['qty']),
                'allocated_base_qty' => DB::raw('allocated_base_qty - '.$line['qty']),
                'updated_at' => $now,
            ]);
        }

        $fullyDispatched = ! OrderLine::query()->where('order_id', $order->id)->whereColumn('dispatched_base_qty', '<', 'base_qty')->exists();
        $order->forceFill($fullyDispatched
            ? ['status' => 'dispatched', 'dispatched_at' => $now]
            : ['status' => 'part_dispatched'])->save();

        $shipment->forceFill([
            'status' => 'dispatched',
            'carrier' => $details->carrier,
            'tracking_number' => $details->trackingNumber,
            'parcel_count' => $details->parcelCount,
            'total_weight_g' => $details->totalWeightG,
            'note' => $details->note ?? $shipment->getAttribute('note'),
            'picked_at' => $shipment->picked_at ?? $now,
            'packed_at' => $shipment->packed_at ?? $now,
            'dispatched_at' => $now,
        ])->save();

        return new DispatchOutcome($shipment, false, $fullyDispatched);
    }

    /**
     * A serial-tracked line leaves with exactly its scanned serials: the
     * picked count must equal the allocation, or the shipment_line_serials
     * trace would not account for every unit.
     *
     * @param  list<StockAllocation>  $allocations
     */
    private function assertSerialsPicked(array $allocations): void
    {
        foreach ($allocations as $allocation) {
            $mode = TrackingMode::from((string) Sku::query()->whereKey($allocation->sku_id)->value('tracking_mode'));
            if (! $mode->tracksSerial()) {
                continue;
            }

            $picked = $this->serialsOf($allocation)->where('status', 'picked')->count();
            if ($picked !== $allocation->base_qty) {
                throw new FulfilmentRejectedException('serials_not_scanned', "A serial-tracked line has {$picked} of {$allocation->base_qty} serials scanned.", 'serials', ['scanned' => $picked, 'required' => $allocation->base_qty]);
            }
        }
    }

    /**
     * @return Builder<StockSerial>
     */
    private function serialsOf(StockAllocation $allocation): Builder
    {
        return StockSerial::query()
            ->where('order_line_id', $allocation->order_line_id)
            ->where('sku_id', $allocation->sku_id)
            ->where('location_id', $allocation->location_id)
            ->when($allocation->batch_id === null, fn ($q) => $q->whereNull('batch_id'), fn ($q) => $q->where('batch_id', $allocation->batch_id));
    }
}
