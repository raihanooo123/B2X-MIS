<?php

namespace App\Domain\Purchasing;

use App\Domain\Pricing\Money;
use App\Domain\Reference\NumberSequenceService;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\Sku;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** GBP purchase orders without containers or landed-cost apportionment. */
final class PurchaseOrderService
{
    public function __construct(private readonly NumberSequenceService $numbers = new NumberSequenceService) {}

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, ?int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actorId): PurchaseOrder {
            [$supplier, $location] = $this->parties($data);
            [$lines, $total] = $this->lines($data);

            $order = PurchaseOrder::create([
                'po_number' => 'PENDING-'.Str::ulid(),
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'status' => 'draft',
                'incoterm' => $supplier->default_incoterm,
                'currency' => 'GBP',
                'goods_total_minor' => $total,
                'goods_total_base_minor' => $total,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'expected_at' => $data['expected_at'] ?? null,
                'note' => $data['note'] ?? null,
                'raised_by_user_id' => $actorId,
            ]);

            $order->lines()->createMany($lines);
            $order->forceFill(['po_number' => $this->numbers->next('po_number')])->save();

            return $order->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only a draft purchase order can be edited.']);
            }

            [$supplier, $location] = $this->parties($data);
            [$lines, $total] = $this->lines($data);

            $order->forceFill([
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'incoterm' => $supplier->default_incoterm,
                'goods_total_minor' => $total,
                'goods_total_base_minor' => $total,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'expected_at' => $data['expected_at'] ?? null,
                'note' => $data['note'] ?? null,
            ])->save();
            $order->lines()->delete();
            $order->lines()->createMany($lines);

            return $order->refresh();
        });
    }

    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only a draft purchase order can be confirmed.']);
            }
            if ($order->supplier()->where('status', 'active')->where('default_currency', 'GBP')->doesntExist()) {
                throw ValidationException::withMessages(['supplier_id' => 'The supplier must be active and use GBP.']);
            }

            $lines = $order->lines()->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one line before confirmation.']);
            }

            $incoming = [];
            foreach ($lines as $line) {
                $pack = Pack::query()->find($line->pack_id);
                $sku = Sku::query()->find($line->sku_id);
                if ($pack === null || $sku === null || $pack->sku_id !== $sku->id || $pack->base_units !== $line->pack_base_units || ! $sku->is_stock_tracked || $sku->status !== 'active') {
                    throw ValidationException::withMessages(['lines' => "Line {$line->line_no} has a changed or unavailable SKU/pack. Edit the draft before confirming."]);
                }
                $incoming[$line->sku_id] = ($incoming[$line->sku_id] ?? 0) + $line->base_qty;
            }

            ksort($incoming);
            foreach ($incoming as $skuId => $baseQty) {
                $this->changeIncoming((int) $skuId, $order->location_id, $baseQty);
            }

            $order->forceFill(['status' => 'confirmed', 'ordered_at' => now()])->save();

            return $order->refresh();
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['draft', 'confirmed', 'part_received'], true)) {
                throw ValidationException::withMessages(['status' => 'This purchase order cannot be cancelled.']);
            }
            if (GoodsReceipt::query()->where('purchase_order_id', $order->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['status' => 'Close the open Goods-in receipt before cancelling this purchase order.']);
            }

            if ($order->status !== 'draft') {
                $outstanding = [];
                foreach ($order->lines()->orderBy('id')->lockForUpdate()->get() as $line) {
                    $remaining = max(0, $line->base_qty - $line->received_base_qty);
                    $outstanding[$line->sku_id] = ($outstanding[$line->sku_id] ?? 0) + $remaining;
                }
                ksort($outstanding);
                foreach ($outstanding as $skuId => $baseQty) {
                    if ($baseQty > 0) {
                        $this->changeIncoming((int) $skuId, $order->location_id, -$baseQty);
                    }
                }
            }

            $order->forceFill(['status' => 'cancelled'])->save();

            return $order->refresh();
        });
    }

    private function changeIncoming(int $skuId, int $locationId, int $delta): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO stock_levels (sku_id, location_id, batch_id, on_hand_base_qty, incoming_base_qty, updated_at)
            VALUES (?, ?, NULL, 0, ?, ?)
            ON CONFLICT ON CONSTRAINT stock_levels_identity_uq DO UPDATE SET
              incoming_base_qty = stock_levels.incoming_base_qty + EXCLUDED.incoming_base_qty,
              version = stock_levels.version + 1,
              updated_at = EXCLUDED.updated_at
        SQL, [$skuId, $locationId, $delta, now()]);
    }

    /** @param array<string, mixed> $data
     * @return array{Supplier, Location}
     */
    private function parties(array $data): array
    {
        $supplier = Supplier::query()->where('status', 'active')->find((int) ($data['supplier_id'] ?? 0));
        $location = Location::query()->find((int) ($data['location_id'] ?? 0));
        if ($supplier === null || $supplier->default_currency !== 'GBP') {
            throw ValidationException::withMessages(['supplier_id' => 'Choose an active GBP supplier.']);
        }
        if ($location === null) {
            throw ValidationException::withMessages(['location_id' => 'Choose a warehouse location.']);
        }

        return [$supplier, $location];
    }

    /** @param array<string, mixed> $data
     * @return array{list<array<string, int|string>>, int}
     */
    private function lines(array $data): array
    {
        $input = $data['lines'] ?? [];
        if (! is_array($input) || $input === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one purchase order line.']);
        }
        if (count($input) > 32767) {
            throw ValidationException::withMessages(['lines' => 'Too many purchase order lines.']);
        }

        $lines = [];
        $total = 0;
        $seen = [];
        foreach (array_values($input) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['lines' => 'Every line needs a SKU, pack, quantity and unit cost.']);
            }
            $sku = Sku::query()->find((int) ($row['sku_id'] ?? 0));
            $pack = Pack::query()->find((int) ($row['pack_id'] ?? 0));
            $quantity = filter_var($row['pack_qty'] ?? null, FILTER_VALIDATE_INT);
            $cost = $this->unitCost($row['unit_fob'] ?? null);
            if ($sku === null || $sku->status !== 'active' || ! $sku->is_stock_tracked || $pack === null || $pack->sku_id !== $sku->id || $quantity === false || $quantity < 1 || $cost === null) {
                throw ValidationException::withMessages(['lines' => 'Each line needs an active stock-tracked SKU, its pack, a positive pack quantity and a valid GBP unit cost.']);
            }
            $identity = $sku->id.':'.$pack->id;
            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(['lines' => 'A SKU and pack can appear only once on a purchase order.']);
            }
            $seen[$identity] = true;
            if ($quantity > intdiv(2147483647, $pack->base_units)) {
                throw ValidationException::withMessages(['lines' => 'The quantity is too large.']);
            }
            $baseQty = $quantity * $pack->base_units;
            if ($cost > 0 && $baseQty > intdiv(PHP_INT_MAX, $cost)) {
                throw ValidationException::withMessages(['lines' => 'The line value is too large.']);
            }
            $lineTotal = Money::roundHalfUpDiv($cost * $baseQty, 100);
            if ($lineTotal > PHP_INT_MAX - $total) {
                throw ValidationException::withMessages(['lines' => 'The purchase order total is too large.']);
            }
            $total += $lineTotal;
            $lines[] = [
                'line_no' => $index + 1,
                'sku_id' => $sku->id,
                'pack_id' => $pack->id,
                'sku_code_snapshot' => $sku->sku_code,
                'pack_qty' => $quantity,
                'pack_base_units' => $pack->base_units,
                'base_qty' => $baseQty,
                'unit_fob_e4' => $cost,
                'line_fob_minor' => $lineTotal,
                'line_fob_base_minor' => $lineTotal,
            ];
        }

        return [$lines, $total];
    }

    private function unitCost(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^\d{1,7}(?:\.\d{1,4})?$/', $value) !== 1) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole) * 10000 + (int) str_pad($fraction, 4, '0');
    }
}
