<?php

namespace App\Domain\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Domain\Inventory\DeadlockRetryPolicy;
use App\Domain\Pricing\Money;
use App\Domain\Warehouse\Events\StockReceived;
use App\Domain\Warehouse\Exceptions\ConcurrentReceiptLineException;
use App\Domain\Warehouse\Exceptions\GoodsInRejectedException;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use App\Models\SkuCost;
use App\Models\StockMovement;
use App\Models\StockSerial;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Goods-in: 05.5 §4, 04 §7.1, 02 §23. Opens a receipt, books each entry
 * as one transaction, and closes the receipt with the variance reasons.
 *
 * **Receiving a line** (receive()) is one transaction, retried whole on
 * deadlock (04 §4.5), locking in 02 §23.3's order:
 *
 *   1. goods_receipts FOR SHARE — the receipt must be open; closing takes
 *      FOR UPDATE, so a close waits for in-flight lines.
 *   2. purchase_order_lines FOR UPDATE — before the batches insert, so two
 *      receipts of one PO line cannot deadlock on batches_sku_code_uq.
 *   3. writes the line references: the sku_costs row (05.5 §4.5), then the
 *      batch, found or created on (sku, batch_code).
 *   4. the goods_receipt_lines insert, ON CONFLICT DO NOTHING on
 *      goods_receipt_lines_idempotency_uq. A concurrent duplicate blocks
 *      here until the first commits, then conflicts, and this attempt
 *      rolls back — including step 3's rows (02 §23.2's correction at
 *      sign-off). A plain retry never gets this far: it is answered from
 *      the committed line before any transaction opens.
 *   5. the goods_in movement (reference_type 'goods_receipt_line'), then
 *      stock_levels, ascending (location, batch NULLS FIRST) for the one
 *      SKU (02 §11.1): incoming off the NULL-batch row, on_hand onto the
 *      received batch's row (02 §23.5). For an untracked SKU that is one
 *      row and one statement.
 *   6. stock_serials: pre-registered `expected` rows move to `in_stock`,
 *      new ones are inserted (02 §7.6); purchase_order_lines.received_base_qty.
 *   7. after commit: StockReceived (05.5 §4.6).
 *
 * Every rule the operative can break is checked before step 3 and throws
 * GoodsInRejectedException, so a rejected entry writes nothing. The
 * batch-code rule is the one 05.5 §4.3 insists on: **a batch-tracked SKU
 * cannot be received without a batch code** — rejected at entry, not
 * accepted and corrected later.
 *
 * **Costs.** A PO line's `unit_fob_e4` is per base unit in the PO's
 * currency. `sku_costs` components sum into `landed_cost_e4`, which is
 * what order-line margin snapshots (03 §11), so they are held in GBP: a
 * foreign-currency FOB is converted once at the PO's `fx_rate_e4`,
 * half-up, and the row records the PO's currency and rate as the source
 * of the figure. A container PO's cost is provisional until the container
 * is apportioned (05.7 §8.5). A manual receipt writes a cost only if one
 * was entered, and it is taken as GBP.
 *
 * **Incoming** (02 §23.5): the NULL-batch row's incoming_base_qty drops
 * by the fall in the PO line's outstanding quantity — never below what
 * 05.7 §5.1's open-PO query would give. PO confirmation does not yet
 * raise incoming (05.7 is not built), so until it does this can take a
 * row's incoming below zero; the nightly incoming reconciliation (04 §9)
 * is what will report it.
 */
final class GoodsInService
{
    /** A container can be received from once it is on its way in (05.7 §6). */
    public const CONTAINER_RECEIVABLE_STATUSES = ['in_transit', 'at_port', 'customs_cleared', 'delivered', 'received'];

    /** Adding stock to these would put it where allocation (02 §7.5) and recall say it is not. */
    private const BATCH_UNRECEIVABLE_STATUSES = ['expired', 'recalled'];

    public function __construct(
        private readonly ExpiryPolicy $expiry = new ExpiryPolicy,
    ) {}

    /**
     * @throws GoodsInRejectedException
     */
    public function open(ReceiptSource $source, ?int $purchaseOrderId, ?int $containerId, ?int $locationId, ?int $actorUserId): GoodsReceipt
    {
        $attributes = match ($source) {
            ReceiptSource::PurchaseOrder => $this->purchaseOrderSource($purchaseOrderId),
            ReceiptSource::Container => $this->containerSource($containerId),
            ReceiptSource::Manual => ['location_id' => $locationId ?? throw new GoodsInRejectedException('location_required', 'Choose the location goods are being received into.', 'location')],
        };

        return GoodsReceipt::create($attributes + [
            'source' => $source->value,
            'status' => 'open',
            'opened_by_user_id' => $actorUserId,
            'opened_at' => now(),
        ]);
    }

    /**
     * @throws GoodsInRejectedException
     */
    public function receive(GoodsReceipt $receipt, ReceiveLine $line): ReceiptLineOutcome
    {
        $existing = $this->findLine($receipt->id, $line);
        if ($existing !== null) {
            return $this->replay($existing, $line);
        }

        try {
            $created = (new DeadlockRetryPolicy)->run(
                fn () => DB::transaction(fn () => $this->receiveWithinTransaction($receipt->id, $line)),
                self::class,
            );
        } catch (ConcurrentReceiptLineException) {
            $committed = $this->findLine($receipt->id, $line)
                ?? throw new LogicException('Receipt line conflicted on its idempotency key but no committed line was found.');

            return $this->replay($committed, $line);
        }

        return new ReceiptLineOutcome($created, false);
    }

    /**
     * Closes the receipt (05.5 §4.2 step 7) and records variance (§4.4,
     * 02 §23.3). Every PO line this receipt touched whose received
     * quantity differs from the ordered quantity needs a decision: a
     * reason for an over-receipt, a reason or "remainder expected" for an
     * under-receipt. Missing decisions are all reported at once. Closing a
     * closed receipt is a no-op.
     *
     * @param  list<VarianceDecision>  $decisions
     *
     * @throws GoodsInRejectedException
     */
    public function close(GoodsReceipt $receipt, array $decisions, ?int $actorUserId): GoodsReceipt
    {
        return (new DeadlockRetryPolicy)->run(
            fn () => DB::transaction(fn () => $this->closeWithinTransaction($receipt->id, $decisions, $actorUserId)),
            self::class,
        );
    }

    /**
     * What the screen pre-fills and warns about for a SKU (05.5 §4.3),
     * so the client shows the same rules the server enforces.
     *
     * @return array{expiry_prefill: ?string, horizon_days: int}
     */
    public function expiryHints(Sku $sku, int $locationId): array
    {
        return [
            'expiry_prefill' => $this->expiry->prefill($sku, ExpiryPolicy::receiptDate())?->toDateString(),
            'horizon_days' => $this->expiry->horizonDays($locationId),
        ];
    }

    /**
     * @return array{purchase_order_id: int, location_id: int}
     */
    private function purchaseOrderSource(?int $purchaseOrderId): array
    {
        $po = PurchaseOrder::query()->find($purchaseOrderId)
            ?? throw new GoodsInRejectedException('purchase_order_not_found', 'No purchase order with that number.', 'reference');

        if (! in_array($po->status, PurchaseOrder::RECEIVABLE_STATUSES, true)) {
            throw new GoodsInRejectedException('purchase_order_not_receivable', "Purchase order {$po->po_number} is {$po->status} and cannot be received against.", 'reference', ['status' => $po->status]);
        }

        return ['purchase_order_id' => $po->id, 'location_id' => $po->location_id];
    }

    /**
     * @return array{container_id: int, location_id: int}
     */
    private function containerSource(?int $containerId): array
    {
        $container = Container::query()->find($containerId)
            ?? throw new GoodsInRejectedException('container_not_found', 'No container with that reference.', 'reference');

        if (! in_array($container->status, self::CONTAINER_RECEIVABLE_STATUSES, true)) {
            throw new GoodsInRejectedException('container_not_receivable', "Container {$container->container_ref} is {$container->status} and cannot be received from.", 'reference', ['status' => $container->status]);
        }

        return ['container_id' => $container->id, 'location_id' => $container->location_id];
    }

    private function receiveWithinTransaction(int $receiptId, ReceiveLine $line): GoodsReceiptLine
    {
        $now = CarbonImmutable::now();

        // 1. The receipt, shared: lines may be added concurrently, a close may not.
        $receipt = GoodsReceipt::query()->sharedLock()->findOrFail($receiptId);
        if (! $receipt->isOpen()) {
            throw new GoodsInRejectedException('receipt_closed', 'This receipt is closed. Open a new receipt to book more goods.', null, [], 409);
        }

        $sku = Sku::query()->findOrFail($line->skuId);
        $pack = Pack::query()->where('sku_id', $sku->id)->find($line->packId)
            ?? throw new GoodsInRejectedException('pack_not_for_sku', 'That pack does not belong to this SKU.', 'pack_code');

        if (! $sku->is_stock_tracked) {
            throw new GoodsInRejectedException('sku_not_stock_tracked', "{$sku->sku_code} is not stock-tracked, so it is not received into stock.", 'sku_id');
        }

        // 2. The PO line, exclusively: two operatives on one line serialise here
        //    and both succeed — receipts are additive (05.5 §12).
        [$poLine, $po] = $this->lockPurchaseOrderLine($receipt, $line, $sku);

        $mode = TrackingMode::from($sku->tracking_mode);
        $baseQty = $line->packQty * $pack->base_units;
        $batchCode = $this->validateCapture($sku, $mode, $line, $baseQty, $receipt->location_id, $now);
        $serials = $this->normalisedSerials($line);
        $this->assertSerialsReceivable($sku, $serials);
        $binId = $this->validateBin($line->binId, $receipt->location_id);

        // 3. What the line references: its cost, then its batch.
        $cost = $this->writeCost($sku, $po, $poLine, $line->unitCostE4, $now);
        $batch = $batchCode === null ? null : $this->findOrCreateBatch($sku, $batchCode, $line->expiresOn, $po, $cost, $now);

        // 4. The line itself — the idempotency guarantee (02 §23.2).
        $inserted = DB::selectOne(<<<'SQL'
            INSERT INTO goods_receipt_lines
              (goods_receipt_id, purchase_order_line_id, client_token, sku_id, pack_id,
               pack_qty, pack_base_units, base_qty, batch_id, bin_id, sku_cost_id,
               received_by_user_id, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT ON CONSTRAINT goods_receipt_lines_idempotency_uq DO NOTHING
            RETURNING id
        SQL, [
            $receipt->id, $poLine?->id, $line->clientToken, $sku->id, $pack->id,
            $line->packQty, $pack->base_units, $baseQty, $batch?->id, $binId, $cost?->id,
            $line->actorUserId, $now,
        ]);

        if (! is_object($inserted) || ! isset($inserted->id)) {
            throw new ConcurrentReceiptLineException;
        }

        $receiptLine = GoodsReceiptLine::query()->findOrFail((int) $inserted->id);

        // 5. The ledger, then the projection.
        $movement = StockMovement::create([
            'occurred_at' => $now,
            'sku_id' => $sku->id,
            'location_id' => $receipt->location_id,
            'batch_id' => $batch?->id,
            'bin_id' => $binId,
            'movement_type' => 'goods_in',
            'base_qty' => $baseQty,
            'reference_type' => 'goods_receipt_line',
            'reference_id' => $receiptLine->id,
            'unit_cost_e4' => $cost?->landed_cost_e4,
            'actor_user_id' => $line->actorUserId,
        ]);

        $incomingDrop = $this->incomingDrop($po, $poLine, $baseQty);
        $this->applyToLevels($sku->id, $receipt->location_id, $batch?->id, $baseQty, $po?->location_id, $incomingDrop, $movement->id, $now);

        // 6. Serials and the PO line's progress.
        $this->receiveSerials($sku, $serials, $receipt->location_id, $binId, $batch?->id, $movement->id, $now);

        $poLine?->increment('received_base_qty', $baseQty);

        // 7. After commit only (04 §4.4).
        $event = new StockReceived($receiptLine->id, $sku->id, $receipt->location_id, $batch?->id, $baseQty);
        DB::afterCommit(fn () => event($event));

        return $receiptLine;
    }

    /**
     * @return array{0: ?PurchaseOrderLine, 1: ?PurchaseOrder}
     */
    private function lockPurchaseOrderLine(GoodsReceipt $receipt, ReceiveLine $line, Sku $sku): array
    {
        if ($receipt->source === ReceiptSource::Manual->value) {
            if ($line->purchaseOrderLineId !== null) {
                throw new GoodsInRejectedException('po_line_not_allowed', 'A manual receipt has no purchase order lines.', 'purchase_order_line');
            }

            return [null, null];
        }

        if ($line->purchaseOrderLineId === null) {
            throw new GoodsInRejectedException('po_line_required', 'Choose the purchase order line these goods are for.', 'purchase_order_line');
        }

        if ($line->unitCostE4 !== null) {
            throw new GoodsInRejectedException('cost_from_purchase_order', 'Cost comes from the purchase order line; it is not entered at receipt.', 'unit_cost_e4');
        }

        $poLine = PurchaseOrderLine::query()->lockForUpdate()->find($line->purchaseOrderLineId);
        $po = $poLine === null ? null : PurchaseOrder::query()->find($poLine->purchase_order_id);

        $onThisReceipt = $po !== null && ($receipt->source === ReceiptSource::PurchaseOrder->value
            ? $po->id === $receipt->purchase_order_id
            : $po->container_id !== null && $po->container_id === $receipt->container_id);

        if ($poLine === null || $po === null || ! $onThisReceipt) {
            throw new GoodsInRejectedException('po_line_not_on_receipt', 'That purchase order line is not part of this receipt.', 'purchase_order_line');
        }

        if ($poLine->sku_id !== $sku->id) {
            throw new GoodsInRejectedException('sku_not_on_po_line', "Line {$poLine->line_no} of {$po->po_number} is for {$poLine->sku_code_snapshot}, not {$sku->sku_code}.", 'sku_id', ['expected_sku_code' => $poLine->sku_code_snapshot]);
        }

        return [$poLine, $po];
    }

    /**
     * 05.5 §4.3's conditional capture. Returns the trimmed batch code, or
     * null for a SKU that is not batch-tracked.
     */
    private function validateCapture(Sku $sku, TrackingMode $mode, ReceiveLine $line, int $baseQty, int $locationId, CarbonImmutable $now): ?string
    {
        $batchCode = $line->batchCode === null ? null : trim($line->batchCode);
        $batchCode = $batchCode === '' ? null : $batchCode;

        if ($mode->tracksBatch()) {
            if ($batchCode === null) {
                throw new GoodsInRejectedException('batch_code_required', "{$sku->sku_code} is batch-tracked: enter or scan the batch code before booking it in.", 'batch_code');
            }

            if ($sku->requires_expiry && $line->expiresOn === null) {
                throw new GoodsInRejectedException('expiry_required', "{$sku->sku_code} requires an expiry date.", 'expires_on');
            }

            if ($line->expiresOn !== null && ! $line->expiryWarningsConfirmed) {
                $warnings = $this->expiry->warnings($line->expiresOn, ExpiryPolicy::receiptDate($now), $this->expiry->horizonDays($locationId));
                if ($warnings !== []) {
                    throw new GoodsInRejectedException('expiry_confirmation_required', 'Check the expiry date: '.implode(' and ', array_map(fn (string $w) => $w === ExpiryPolicy::WARNING_PAST ? 'it is in the past' : 'it is unusually far ahead', $warnings)).'. Confirm to book it anyway.', 'expires_on', ['warnings' => $warnings]);
                }
            }
        } else {
            if ($batchCode !== null) {
                throw new GoodsInRejectedException('batch_not_tracked', "{$sku->sku_code} is not batch-tracked, so it takes no batch code.", 'batch_code');
            }

            if ($line->expiresOn !== null) {
                throw new GoodsInRejectedException('expiry_not_tracked', "{$sku->sku_code} is not batch-tracked, so an expiry date has nowhere to be recorded.", 'expires_on');
            }
        }

        if ($mode->tracksSerial()) {
            $serials = $this->normalisedSerials($line);
            if (count($serials) !== $baseQty) {
                throw new GoodsInRejectedException('serial_count_mismatch', "{$sku->sku_code} is serial-tracked: capture one serial per unit — {$baseQty} expected, ".count($serials).' captured.', 'serials', ['expected' => $baseQty, 'captured' => count($serials)]);
            }

            if (count(array_unique($serials)) !== count($serials)) {
                throw new GoodsInRejectedException('serial_duplicated_in_entry', 'The same serial appears twice in this entry.', 'serials', ['duplicates' => array_values(array_unique(array_diff_assoc($serials, array_unique($serials))))]);
            }
        } elseif ($line->serials !== []) {
            throw new GoodsInRejectedException('serial_not_tracked', "{$sku->sku_code} is not serial-tracked, so it takes no serials.", 'serials');
        }

        return $batchCode;
    }

    /**
     * @return list<string>
     */
    private function normalisedSerials(ReceiveLine $line): array
    {
        return array_values(array_filter(array_map('trim', $line->serials), fn (string $s) => $s !== ''));
    }

    /**
     * A serial is receivable if it is new or pre-registered `expected`
     * (02 §7.6). Anything else was received before: rejected, naming that
     * receipt (05.5 §4.3). Read without a lock — receiveSerials() guards
     * the write, so a concurrent receipt of the same serial still fails.
     *
     * @param  list<string>  $serials
     */
    private function assertSerialsReceivable(Sku $sku, array $serials): void
    {
        if ($serials === []) {
            return;
        }

        $taken = StockSerial::query()
            ->where('sku_id', $sku->id)
            ->whereIn('serial_number', $serials)
            ->where('status', '<>', 'expected')
            ->orderBy('serial_number')
            ->first();

        if ($taken !== null) {
            throw $this->serialAlreadyReceived($sku, $taken);
        }
    }

    private function serialAlreadyReceived(Sku $sku, StockSerial $serial): GoodsInRejectedException
    {
        $prior = $this->receiptOfMovement($serial->received_movement_id);
        $where = $prior === null ? 'before' : "on receipt {$prior->public_id} (".$prior->opened_at->timezone(ExpiryPolicy::TIMEZONE)->format('j M Y').')';

        return new GoodsInRejectedException('serial_already_received', "Serial {$serial->serial_number} of {$sku->sku_code} was already received {$where}.", 'serials', [
            'serial_number' => $serial->serial_number,
            'status' => $serial->status,
            'prior_receipt_id' => $prior?->public_id,
            'prior_received_at' => $prior?->opened_at->toIso8601String(),
        ]);
    }

    private function receiptOfMovement(?int $movementId): ?GoodsReceipt
    {
        if ($movementId === null) {
            return null;
        }

        $lineId = StockMovement::query()
            ->where('id', $movementId)
            ->where('reference_type', 'goods_receipt_line')
            ->value('reference_id');

        if ($lineId === null) {
            return null;
        }

        $receiptId = GoodsReceiptLine::query()->whereKey($lineId)->value('goods_receipt_id');

        return $receiptId === null ? null : GoodsReceipt::query()->find((int) $receiptId);
    }

    private function validateBin(?int $binId, int $locationId): ?int
    {
        if ($binId === null) {
            return null;
        }

        if (! Bin::query()->whereKey($binId)->where('location_id', $locationId)->exists()) {
            throw new GoodsInRejectedException('bin_not_at_location', 'That bin is not at the location this receipt is for.', 'bin_code');
        }

        return $binId;
    }

    private function writeCost(Sku $sku, ?PurchaseOrder $po, ?PurchaseOrderLine $poLine, ?int $manualUnitCostE4, CarbonImmutable $now): ?SkuCost
    {
        if ($po !== null && $poLine !== null) {
            $foreign = $po->currency !== 'GBP';
            $fxRateE4 = $po->fx_rate_e4;
            if ($foreign && $fxRateE4 === null) {
                throw new LogicException("Purchase order {$po->po_number} is in {$po->currency} with no FX rate (purchase_orders_fx_chk).");
            }

            // refresh(): landed_cost_e4 is generated, so only a re-read has it.
            return SkuCost::create([
                'sku_id' => $sku->id,
                'source' => 'purchase_order',
                'purchase_order_id' => $po->id,
                'container_id' => $po->container_id,
                'currency' => $po->currency,
                'fx_rate_e4' => $foreign ? $fxRateE4 : null,
                'fob_e4' => $foreign ? Money::roundHalfUpDiv($poLine->unit_fob_e4 * (int) $fxRateE4, 10000) : $poLine->unit_fob_e4,
                'is_provisional' => $po->container_id !== null,
                'valid_from' => $now,
            ])->refresh();
        }

        if ($manualUnitCostE4 === null) {
            return null;
        }

        return SkuCost::create([
            'sku_id' => $sku->id,
            'source' => 'manual',
            'currency' => 'GBP',
            'fob_e4' => $manualUnitCostE4,
            'valid_from' => $now,
        ])->refresh();
    }

    /**
     * 05.5 §12: a batch code already on this SKU adds to that batch, but
     * only if the expiry matches — otherwise it is a different batch and
     * the screen says so. Created with ON CONFLICT so two receipts of one
     * new batch meet at batches_sku_code_uq rather than failing.
     */
    private function findOrCreateBatch(Sku $sku, string $batchCode, ?CarbonImmutable $expiresOn, ?PurchaseOrder $po, ?SkuCost $cost, CarbonImmutable $now): Batch
    {
        $expiry = $expiresOn?->toDateString();

        $created = DB::selectOne(<<<'SQL'
            INSERT INTO batches (sku_id, batch_code, purchase_order_id, container_id, sku_cost_id,
                                 unit_cost_e4, expires_on, received_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ON CONFLICT ON CONSTRAINT batches_sku_code_uq DO NOTHING
            RETURNING id
        SQL, [$sku->id, $batchCode, $po?->id, $po?->container_id, $cost?->id, $cost?->landed_cost_e4, $expiry, $now]);

        if (is_object($created) && isset($created->id)) {
            return Batch::query()->findOrFail((int) $created->id);
        }

        $batch = Batch::query()->where('sku_id', $sku->id)->where('batch_code', $batchCode)->lockForUpdate()->firstOrFail();

        if ($batch->expires_on?->toDateString() !== $expiry) {
            throw new GoodsInRejectedException('batch_expiry_mismatch', "Batch {$batchCode} is already recorded with expiry ".($batch->expires_on?->format('j M Y') ?? 'none').'. A different expiry means a different batch — check the code.', 'expires_on', ['batch_code' => $batchCode, 'recorded_expires_on' => $batch->expires_on?->toDateString()]);
        }

        if (in_array($batch->status, self::BATCH_UNRECEIVABLE_STATUSES, true)) {
            throw new GoodsInRejectedException('batch_not_receivable', "Batch {$batchCode} is {$batch->status}; stock cannot be booked into it.", 'batch_code', ['status' => $batch->status]);
        }

        if ($batch->status === 'depleted') {
            // More of a batch that had run out: allocatable again (02 §7.5's FEFO predicate).
            $batch->forceFill(['status' => 'active'])->save();
        }

        return $batch;
    }

    /**
     * The fall in the PO line's outstanding quantity (02 §23.5): what
     * 05.7 §5.1's open-PO query stops counting as incoming. Zero for a
     * manual receipt, a PO no longer in the open set, or an over-receipt
     * beyond what was outstanding.
     */
    private function incomingDrop(?PurchaseOrder $po, ?PurchaseOrderLine $poLine, int $baseQty): int
    {
        if ($po === null || $poLine === null || ! in_array($po->status, PurchaseOrder::RECEIVABLE_STATUSES, true)) {
            return 0;
        }

        return max(0, min($baseQty, $poLine->base_qty - $poLine->received_base_qty));
    }

    /**
     * on_hand onto the received row, incoming off the PO location's
     * NULL-batch row (02 §23.5), in 02 §11.1's order: the NULL-batch row
     * sorts first. One statement when they are the same row.
     */
    private function applyToLevels(int $skuId, int $locationId, ?int $batchId, int $onHandIncrease, ?int $incomingLocationId, int $incomingDrop, int $movementId, CarbonImmutable $now): void
    {
        /** @var array<string, array{location_id: int, batch_id: ?int, on_hand: int, incoming: int, movement_id: ?int}> $rows */
        $rows = [];

        if ($incomingDrop > 0 && $incomingLocationId !== null) {
            $rows[$incomingLocationId.':null'] = ['location_id' => $incomingLocationId, 'batch_id' => null, 'on_hand' => 0, 'incoming' => -$incomingDrop, 'movement_id' => null];
        }

        $key = $locationId.':'.($batchId ?? 'null');
        $rows[$key] = [
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'on_hand' => $onHandIncrease,
            'incoming' => $rows[$key]['incoming'] ?? 0,
            'movement_id' => $movementId,
        ];

        uasort($rows, fn (array $a, array $b) => [$a['location_id'], $a['batch_id'] ?? -1] <=> [$b['location_id'], $b['batch_id'] ?? -1]);

        foreach ($rows as $row) {
            $this->upsertLevel($skuId, $row['location_id'], $row['batch_id'], $row['on_hand'], $row['incoming'], $row['movement_id'], $now);
        }
    }

    private function upsertLevel(int $skuId, int $locationId, ?int $batchId, int $onHandDelta, int $incomingDelta, ?int $movementId, CarbonImmutable $now): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO stock_levels (sku_id, location_id, batch_id, on_hand_base_qty, incoming_base_qty,
                                      last_movement_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT ON CONSTRAINT stock_levels_identity_uq DO UPDATE SET
              on_hand_base_qty  = stock_levels.on_hand_base_qty  + EXCLUDED.on_hand_base_qty,
              incoming_base_qty = stock_levels.incoming_base_qty + EXCLUDED.incoming_base_qty,
              version           = stock_levels.version + 1,
              last_movement_id  = COALESCE(EXCLUDED.last_movement_id, stock_levels.last_movement_id),
              updated_at        = EXCLUDED.updated_at
        SQL, [$skuId, $locationId, $batchId, $onHandDelta, $incomingDelta, $movementId, $now]);
    }

    /**
     * @param  list<string>  $serials
     */
    private function receiveSerials(Sku $sku, array $serials, int $locationId, ?int $binId, ?int $batchId, int $movementId, CarbonImmutable $now): void
    {
        foreach ($serials as $serialNumber) {
            $received = [
                'status' => 'in_stock',
                'location_id' => $locationId,
                'bin_id' => $binId,
                'batch_id' => $batchId,
                'received_movement_id' => $movementId,
                'received_at' => $now,
                'updated_at' => $now,
            ];

            // Pre-registered from a manifest: transition, never duplicate (02 §7.6).
            $transitioned = StockSerial::query()
                ->where('sku_id', $sku->id)
                ->where('serial_number', $serialNumber)
                ->where('status', 'expected')
                ->update($received);

            if ($transitioned === 1) {
                continue;
            }

            try {
                StockSerial::create(['sku_id' => $sku->id, 'serial_number' => $serialNumber] + $received);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) !== '23505') {
                    throw $e;
                }

                // A concurrent receipt booked it first (stock_serials_sku_number_uq).
                throw new GoodsInRejectedException('serial_already_received', "Serial {$serialNumber} of {$sku->sku_code} was received by another entry just now.", 'serials', ['serial_number' => $serialNumber]);
            }
        }
    }

    /**
     * @param  list<VarianceDecision>  $decisions
     */
    private function closeWithinTransaction(int $receiptId, array $decisions, ?int $actorUserId): GoodsReceipt
    {
        $now = CarbonImmutable::now();

        $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receiptId);
        if (! $receipt->isOpen()) {
            return $receipt;
        }

        $touchedLineIds = GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receipt->id)
            ->whereNotNull('purchase_order_line_id')
            ->distinct()
            ->orderBy('purchase_order_line_id')
            ->pluck('purchase_order_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $decisionsByLine = [];
        foreach ($decisions as $decision) {
            if (! in_array($decision->purchaseOrderLineId, $touchedLineIds, true)) {
                throw new GoodsInRejectedException('variance_line_not_on_receipt', 'A variance was given for a purchase order line this receipt did not receive.', 'variances');
            }
            $decisionsByLine[$decision->purchaseOrderLineId] = $decision;
        }

        if ($touchedLineIds !== []) {
            $poIds = PurchaseOrderLine::query()->whereIn('id', $touchedLineIds)->distinct()->orderBy('purchase_order_id')->pluck('purchase_order_id')->all();
            $pos = PurchaseOrder::query()->whereIn('id', $poIds)->orderBy('id')->lockForUpdate()->get();
            $lines = PurchaseOrderLine::query()->whereIn('id', $touchedLineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $this->recordVariances($lines->all(), $pos->keyBy('id')->all(), $decisionsByLine);

            foreach ($pos as $po) {
                $this->advancePurchaseOrder($po, $now);
            }
        }

        $receipt->forceFill([
            'status' => 'closed',
            'closed_at' => $now,
            'closed_by_user_id' => $actorUserId,
        ])->save();

        return $receipt;
    }

    /**
     * @param  array<int, PurchaseOrderLine>  $lines
     * @param  array<int, PurchaseOrder>  $pos
     * @param  array<int, VarianceDecision>  $decisionsByLine
     */
    private function recordVariances(array $lines, array $pos, array $decisionsByLine): void
    {
        $missing = [];

        foreach ($lines as $line) {
            $difference = $line->received_base_qty - $line->base_qty;
            if ($difference === 0) {
                continue;
            }

            $decision = $decisionsByLine[$line->id] ?? null;
            $poNumber = $pos[$line->purchase_order_id]->po_number;
            $meta = [
                'po_number' => $poNumber,
                'line_no' => $line->line_no,
                'ordered_base_qty' => $line->base_qty,
                'received_base_qty' => $line->received_base_qty,
            ];

            if ($difference > 0 && $decision?->reason === null) {
                $missing[] = ['field' => "variances.{$poNumber}.{$line->line_no}", 'code' => 'over_receipt_reason_required', 'message' => "{$poNumber} line {$line->line_no}: {$line->received_base_qty} received against {$line->base_qty} ordered. Give a reason.", 'meta' => $meta];

                continue;
            }

            if ($difference < 0 && $decision === null) {
                $missing[] = ['field' => "variances.{$poNumber}.{$line->line_no}", 'code' => 'short_receipt_decision_required', 'message' => "{$poNumber} line {$line->line_no}: {$line->received_base_qty} received against {$line->base_qty} ordered. Give a reason, or mark the remainder as expected.", 'meta' => $meta];

                continue;
            }

            if ($decision->reason !== null) {
                $line->forceFill(['variance_reason' => $decision->reason->value])->save();
            }
        }

        if ($missing !== []) {
            throw new GoodsInRejectedException('variance_reason_required', 'Some lines were received short or over. Each needs a reason before the receipt can close.', 'variances', [], 422, $missing);
        }
    }

    /**
     * A PO whose every line is final — fully received, or closed short or
     * over with a reason — is `received`; otherwise `part_received`. A PO
     * leaving 05.7 §5.1's open set stops counting any remaining
     * outstanding quantity as incoming (02 §23.5).
     */
    private function advancePurchaseOrder(PurchaseOrder $po, CarbonImmutable $now): void
    {
        if (! in_array($po->status, PurchaseOrder::RECEIVABLE_STATUSES, true)) {
            return;
        }

        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->orderBy('id')->get();
        $final = $lines->every(fn (PurchaseOrderLine $l) => $l->received_base_qty >= $l->base_qty || $l->variance_reason !== null);

        if (! $final) {
            if ($po->status !== 'part_received') {
                $po->forceFill(['status' => 'part_received'])->save();
            }

            return;
        }

        $po->forceFill(['status' => 'received', 'received_at' => $now])->save();

        $outstandingBySku = [];
        foreach ($lines as $line) {
            $outstanding = $line->base_qty - $line->received_base_qty;
            if ($outstanding > 0) {
                $outstandingBySku[$line->sku_id] = ($outstandingBySku[$line->sku_id] ?? 0) + $outstanding;
            }
        }

        ksort($outstandingBySku);
        foreach ($outstandingBySku as $skuId => $outstanding) {
            $this->upsertLevel($skuId, $po->location_id, null, 0, -$outstanding, null, $now);
        }
    }

    private function findLine(int $receiptId, ReceiveLine $line): ?GoodsReceiptLine
    {
        return GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receiptId)
            ->where('client_token', $line->clientToken)
            ->when(
                $line->purchaseOrderLineId === null,
                fn ($q) => $q->whereNull('purchase_order_line_id'),
                fn ($q) => $q->where('purchase_order_line_id', $line->purchaseOrderLineId),
            )
            ->first();
    }

    /**
     * The same key must mean the same entry (06 §6): a replay whose SKU,
     * pack, quantity, batch or bin differ is a different receipt reusing
     * a key, and is refused rather than silently answered with the first.
     */
    private function replay(GoodsReceiptLine $existing, ReceiveLine $line): ReceiptLineOutcome
    {
        $batchCode = $line->batchCode === null || trim($line->batchCode) === '' ? null : trim($line->batchCode);
        $existingBatchCode = $existing->batch_id === null ? null : Batch::query()->whereKey($existing->batch_id)->value('batch_code');

        $same = $existing->sku_id === $line->skuId
            && $existing->pack_id === $line->packId
            && $existing->pack_qty === $line->packQty
            && $existingBatchCode === $batchCode
            && $existing->bin_id === $line->binId;

        if (! $same) {
            throw new GoodsInRejectedException('idempotency_key_reuse', 'This entry reuses the key of a different, already-booked entry.', null, [], 409);
        }

        return new ReceiptLineOutcome($existing, true);
    }
}
