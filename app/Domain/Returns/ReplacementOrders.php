<?php

namespace App\Domain\Returns;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Delivery\ZoneResolver;
use App\Domain\Inventory\AllocationLine;
use App\Domain\Inventory\AllocationService;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\DeliveryAddress;
use App\Domain\Ordering\OrderKind;
use App\Domain\Pricing\PriceSource;
use App\Domain\Reference\NumberSequenceService;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderLine;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Models\Sku;
use App\Models\SkuCost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 05.4 §14 — a replacement order: zero value, sent to the same customer to
 * replace faulty goods, like-for-like (same SKU and pack, whole packs).
 *
 *   - `order_kind = 'replacement'`, `payment_status = 'not_required'`,
 *     `payment_method` NULL — so the warehouse may work it at once
 *     (FulfilmentRules) — zero totals and free carriage. No invoice or
 *     receipt (InvoiceService refuses one, Q-R3). No consumer
 *     cancellation is offered on it (CancellationEligibility, the order
 *     page); a fault on it is reported against its own lines, which name
 *     the faulty line through `replaces_order_line_id`.
 *   - Lines: `price_source = 'replacement'`, every money column 0, the
 *     faulty line's snapshots, and today's cost (R5) so the replacement's
 *     real cost is reported against the return. Never shown to the
 *     customer (invariant 9).
 *   - One replacement per RMA (`rmas_replacement_order_uq`; the RMA row is
 *     locked and re-checked first).
 *
 * Created either when a return is settled as a replacement
 * (ReturnResolution, from `inspected`), or — staff only, with a reason
 * (Q-R2) — as an **advance replacement** while the faulty goods are still
 * on their way back. An advance replacement leaves the return open for
 * the goods; settling it later reuses this order.
 *
 * The transaction (§14.4): the RMA row first, which every return path
 * locks before anything in the global order (02 §11.1); then the order and
 * its lines are inserted; then stock is allocated through 04 §4.2 with no
 * `companies` lock (nothing is held on credit); the order number last
 * (02 §11.3). A shortfall rolls everything back and the return is
 * unchanged (R14).
 */
final class ReplacementOrders
{
    /** Statuses an advance replacement may be made from: approved, goods not yet inspected. */
    private const ADVANCE_STATUSES = ['approved', 'awaiting_goods', 'received'];

    public function __construct(
        private readonly AllocationService $allocations = new AllocationService,
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly ZoneResolver $zones = new ZoneResolver,
    ) {}

    /**
     * Staff only (RmaPolicy `resolve`), with a reason (Q-R2).
     *
     * @throws ReturnActionRefusedException
     */
    public function createAdvance(int $rmaId, int $staffUserId, string $reason, ?DeliveryAddress $address = null): Order
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ReturnActionRefusedException('reason_required', 'Give a reason for sending the replacement before the goods come back.');
        }

        return DB::transaction(function () use ($rmaId, $staffUserId, $reason, $address): Order {
            $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);

            if ($rma->replacement_order_id !== null) {
                throw new ReturnActionRefusedException('already_replaced', "Return {$rma->rma_number} already has a replacement order.");
            }
            if ($rma->company_id !== null) {
                throw new ReturnActionRefusedException('trade_return', 'Trade returns are settled under 05.4 §7.5, not here.');
            }
            if ($rma->return_reason === 'consumer_cancellation') {
                throw new ReturnActionRefusedException('not_faulty', 'A cancellation is refunded, not replaced.');
            }
            if (! in_array($rma->status, self::ADVANCE_STATUSES, true) || $rma->approved_at === null) {
                throw new ReturnActionRefusedException('not_ready', "An advance replacement is made once the return is approved and before it is settled (it is {$rma->status}).");
            }
            if (FaultReports::withinRejectPeriod($rma)) {
                throw new ReturnActionRefusedException('refund_required', 'Within 30 days of delivery, faulty goods are refunded in full.');
            }
            $record = json_decode($rma->internal_note ?? '{}', true);
            if (! is_array($record) || ($record['customer_choice'] ?? null) !== 'replacement') {
                throw new ReturnActionRefusedException('customer_choice_required', 'The customer chose a repair, not a replacement.');
            }

            $quantities = [];
            foreach (RmaLine::query()->where('rma_id', $rma->id)->orderBy('line_no')->get() as $line) {
                $quantities[$line->id] = $line->requested_base_qty;
            }

            $order = $this->createWithinTransaction($rma, $quantities, $staffUserId, $address);

            $record['decisions'][] = ['advance_replacement' => $order->order_number, 'reason' => $reason, 'staff_user_id' => $staffUserId, 'at' => now()->toIso8601String()];
            $rma->forceFill([
                'replacement_order_id' => $order->id,
                'resolution_type' => 'replacement',
                'internal_note' => json_encode($record, JSON_THROW_ON_ERROR),
            ])->save();

            $this->audit->record(new AuditEntry(
                action: AuditAction::RmaAdvanceReplacement,
                actorType: 'user',
                actorUserId: $staffUserId,
                subjectType: 'rma',
                subjectId: $rma->id,
                before: ['replacement_order_id' => null],
                after: ['replacement_order_id' => $order->id],
                reason: $reason,
            ));

            return $order;
        });
    }

    /**
     * Create the order inside the caller's transaction, with the RMA row
     * already locked by it. Quantities are per RMA line, in base units.
     *
     * @param  array<int, int>  $quantities  rma_line id => base quantity to replace
     *
     * @throws ReturnActionRefusedException
     */
    public function createWithinTransaction(Rma $rma, array $quantities, int $staffUserId, ?DeliveryAddress $address = null): Order
    {
        if ($rma->replacement_order_id !== null) {
            throw new ReturnActionRefusedException('already_replaced', "Return {$rma->rma_number} already has a replacement order.");
        }

        $original = Order::query()->findOrFail($rma->order_id);
        $rmaLines = RmaLine::query()->where('rma_id', $rma->id)->whereIn('id', array_keys($quantities))->orderBy('line_no')->get();
        $faultyLines = OrderLine::query()->whereIn('id', $rmaLines->pluck('order_line_id'))->get()->keyBy('id');
        $location = Location::query()->where('is_default', true)->where('is_sellable', true)->firstOrFail();

        $wanted = [];
        foreach ($rmaLines as $rmaLine) {
            $qty = $quantities[$rmaLine->id] ?? 0;
            if ($qty <= 0) {
                continue;
            }
            $maximum = $rma->status === 'inspected'
                ? $rmaLine->restocked_base_qty + $rmaLine->quarantined_base_qty + $rmaLine->written_off_base_qty
                : $rmaLine->requested_base_qty;
            if ($qty > $maximum) {
                throw new ReturnActionRefusedException('too_many', 'A replacement cannot exceed the accepted faulty quantity.');
            }
            $faulty = $faultyLines->get($rmaLine->order_line_id)
                ?? throw new ReturnActionRefusedException('line_missing', "Order line {$rmaLine->order_line_id} of {$rma->rma_number} is missing.");
            if ($qty % $faulty->pack_base_units !== 0) {
                throw new ReturnActionRefusedException('not_whole_packs', "{$faulty->sku_code_snapshot}: {$qty} is not a whole number of {$faulty->pack_label_snapshot} packs. Settle it with a refund instead.");
            }
            $wanted[] = [$faulty, $qty];
        }
        if ($wanted === []) {
            throw new ReturnActionRefusedException('nothing_to_replace', "Nothing on return {$rma->rma_number} is left to replace.");
        }

        $zoneId = $original->delivery_zone_id;
        if ($address !== null && $original->company_id === null && strtoupper($address->countryCode) !== 'GB') {
            throw new ReturnActionRefusedException('country_not_served', 'We deliver to Great Britain only (05.15 §6.1 rule G).');
        }
        if ($address !== null) {
            $zoneId = $this->zones->resolve($address->postcode, $address->countryCode)->zone?->id;
        }

        $now = now();
        $order = Order::query()->create([
            // Overwritten with the gapless number last (02 §11.3), as CheckoutService does.
            'order_number' => (string) Str::ulid(),
            'order_kind' => OrderKind::Replacement->value,
            'company_id' => $original->company_id,
            'user_id' => $original->user_id,
            'guest_email' => $original->guest_email,
            'placed_by_user_id' => $staffUserId,
            'channel' => $original->getAttribute('channel'),
            'status' => 'draft',
            'payment_status' => OrderKind::NOT_REQUIRED,
            'payment_method' => null,
            'fulfilment_type' => 'delivery',
            'currency' => $original->currency,
            'subtotal_net_minor' => 0,
            'shipping_net_minor' => 0,
            // A consumer replacement owes no statutory delivery refund, so 0; trade NULL (02 §26.3).
            'standard_shipping_net_minor' => $original->company_id === null ? 0 : null,
            'shipping_tax_minor' => 0,
            'shipping_tax_rate_bp' => $original->shipping_tax_rate_bp,
            'delivery_zone_id' => $zoneId,
            'delivery_rate_id' => null,
            'delivery_method' => $original->delivery_method,
            'tax_minor' => 0,
            'total_gross_minor' => 0,
            'customer_reference' => "Replacement for {$original->order_number} ({$rma->rma_number})",
        ]);

        $this->copyAddresses($original, $order, $address);

        $allocationLines = [];
        foreach ($wanted as $i => [$faulty, $qty]) {
            $sku = Sku::query()->findOrFail($faulty->sku_id);
            $cost = SkuCost::query()->where('sku_id', $sku->id)->where('valid_from', '<=', $now)->orderByDesc('valid_from')->first(['id', 'landed_cost_e4']);

            $line = OrderLine::query()->create([
                'order_id' => $order->id,
                'line_no' => $i + 1,
                'sku_id' => $faulty->sku_id,
                'pack_id' => $faulty->pack_id,
                'sku_code_snapshot' => $faulty->sku_code_snapshot,
                'name_snapshot' => $faulty->name_snapshot,
                'pack_label_snapshot' => $faulty->pack_label_snapshot,
                'pack_qty' => intdiv($qty, $faulty->pack_base_units),
                'pack_base_units' => $faulty->pack_base_units,
                'base_qty' => $qty,
                'unit_price_net_e4' => 0,
                'line_discount_minor' => 0,
                'line_spend_discount_minor' => 0,
                'line_net_minor' => 0,
                'tax_rate_bp' => $faulty->tax_rate_bp,
                'line_tax_minor' => 0,
                'line_gross_minor' => 0,
                'price_source' => PriceSource::Replacement->value,
                'replaces_order_line_id' => $faulty->id,
                'unit_cost_e4' => $cost?->landed_cost_e4,
                'sku_cost_id' => $cost?->id,
            ]);

            if (! $sku->is_stock_tracked) {
                continue;
            }
            if ($sku->tracking_mode !== 'none'
                && ($sku->tracking_mode !== 'batch' || ! in_array($sku->allocation_strategy, ['fefo', 'fifo', 'lifo'], true))) {
                throw new ReturnActionRefusedException('tracking_not_supported', "{$sku->sku_code} is serial-tracked; create this replacement by hand.");
            }
            $allocationLines[] = new AllocationLine($line->id, $sku->id, $location->id, null, $qty, selectBatch: $sku->tracking_mode === 'batch');
        }

        if ($allocationLines !== []) {
            try {
                $this->allocations->allocateWithinTransaction(null, 0, $allocationLines);
            } catch (InsufficientStockException) {
                throw new ReturnActionRefusedException('out_of_stock', 'There is not enough stock to send the replacement. Try again when stock arrives, or refund the customer instead.');
            }
        }

        $order->forceFill([
            'order_number' => $this->numbers->next('order_number'),
            'status' => 'confirmed',
            'placed_at' => $now,
            'confirmed_at' => $now,
        ])->save();

        $rma->replacement_order_id = $order->id;
        $rma->resolution_type = 'replacement';

        $this->notifications->rmaReplacementCreated($rma->id);

        return $order;
    }

    private function copyAddresses(Order $original, Order $order, ?DeliveryAddress $address): void
    {
        foreach (OrderAddress::query()->where('order_id', $original->id)->get() as $snapshot) {
            if ($snapshot->getAttribute('address_type') === 'delivery' && $address !== null) {
                continue;
            }
            OrderAddress::query()->create(['order_id' => $order->id] + array_intersect_key(
                $snapshot->getAttributes(),
                array_flip(['address_type', 'contact_name', 'phone', 'company_name', 'line1', 'line2', 'city', 'county', 'postcode', 'country_code']),
            ));
        }

        if ($address !== null) {
            OrderAddress::query()->create(['order_id' => $order->id, 'address_type' => 'delivery'] + $address->toSnapshot());
        }
    }
}
