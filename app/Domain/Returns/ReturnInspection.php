<?php

namespace App\Domain\Returns;

use App\Domain\Pricing\Money;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Models\Shipment;
use App\Models\Sku;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 05.4 §7.4, §13.5 — the goods that arrived are inspected and each line is
 * split between dispositions:
 *
 *   - restock: a `return_in` movement (04 §3, reference `rma`) at the
 *     location it was dispatched from, at the batch recovered at receipt.
 *     A batch-tracked line with no recovered batch cannot be restocked
 *     (04 §7.3) — quarantine it instead. The only disposition that moves
 *     stock;
 *   - quarantine, write-off: no movement (04 §7.3: only restock writes
 *     `return_in`); refunded;
 *   - return to the customer: what arrived and is neither of those — for a
 *     consumer, an unsealed hygiene item (reg. 28(1)(e)), not refunded.
 *
 * Diminished value (CCR reg. 34(9)) only on a consumer cancellation, never
 * on faulty goods, never automatic, always with a reason
 * (`rma_lines_diminished_chk`), and never more than the goods refunded.
 *
 * One transaction: the RMA and its lines, then `stock_levels` in the 02
 * §11.1 order (ascending sku, location, batch NULLS FIRST) — nothing else
 * is locked first.
 */
final class ReturnInspection
{
    /**
     * @param  array<int, array{restock: int, quarantine: int, write_off: int, diminished_value_minor?: int, diminished_value_reason?: string|null, disposition_reason?: string|null}>  $byLineNo
     *
     * @throws ReturnActionRefusedException
     */
    public function inspect(int $rmaId, array $byLineNo, int $staffUserId): Rma
    {
        return DB::transaction(function () use ($rmaId, $byLineNo, $staffUserId): Rma {
            $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
            if ($rma->status !== 'received') {
                throw new ReturnActionRefusedException('not_received', "Return {$rma->rma_number} has not been received (it is {$rma->status}).");
            }

            $lines = RmaLine::query()->where('rma_id', $rma->id)->orderBy('line_no')->lockForUpdate()->get()->keyBy('line_no');
            $location = (int) Shipment::query()->where('order_id', $rma->order_id)->orderBy('id')->value('location_id');
            $now = CarbonImmutable::now();
            $restocks = [];

            foreach ($lines as $lineNo => $line) {
                /** @var RmaLine $line */
                $decision = $byLineNo[$lineNo] ?? null;
                if ($decision === null) {
                    throw new ReturnActionRefusedException('line_missing', "Give a decision for every line (line {$lineNo} is missing).");
                }

                $restock = $decision['restock'];
                $quarantine = $decision['quarantine'];
                $writeOff = $decision['write_off'];
                if (min($restock, $quarantine, $writeOff) < 0 || $restock + $quarantine + $writeOff > $line->received_base_qty) {
                    throw new ReturnActionRefusedException('quantity_out_of_range', "Line {$lineNo}: the split cannot exceed the {$line->received_base_qty} received.");
                }

                $diminished = $decision['diminished_value_minor'] ?? 0;
                $diminishedReason = isset($decision['diminished_value_reason']) ? trim((string) $decision['diminished_value_reason']) : null;
                $this->assertDiminished($rma, $line, $lineNo, $diminished, $diminishedReason, $restock + $quarantine + $writeOff);

                $sku = Sku::query()->findOrFail($line->sku_id);
                if ($restock > 0 && $sku->tracking_mode === 'batch' && $line->batch_id === null) {
                    throw new ReturnActionRefusedException('batch_unknown', "Line {$lineNo}: the batch could not be recovered, so it cannot be restocked. Quarantine it instead.");
                }

                $returned = $line->received_base_qty - $restock - $quarantine - $writeOff;
                $line->fill([
                    'restocked_base_qty' => $restock,
                    'quarantined_base_qty' => $quarantine,
                    'written_off_base_qty' => $writeOff,
                    'disposition' => match (true) {
                        $restock > 0 => 'restock',
                        $quarantine > 0 => 'quarantine',
                        $writeOff > 0 => 'write_off',
                        default => 'return_to_customer',
                    },
                    'disposition_reason' => $decision['disposition_reason'] ?? ($returned > 0 && $sku->getAttribute('non_refundable_reason') === 'hygiene' ? 'Hygiene seal broken: returned to the customer, not refunded.' : null),
                    'diminished_value_minor' => $diminished,
                    'diminished_value_reason' => $diminished > 0 ? $diminishedReason : null,
                    'inspected_by_user_id' => $staffUserId,
                    'inspected_at' => $now,
                ])->save();

                if ($restock > 0) {
                    $restocks[] = ['sku_id' => $line->sku_id, 'location_id' => $location, 'batch_id' => $line->batch_id, 'qty' => $restock, 'line_no' => $lineNo];
                }
            }

            $this->restock($rma, $restocks, $staffUserId, $now);

            $rma->status = 'inspected';
            $rma->setAttribute('inspected_at', $now);
            $rma->handled_by_user_id = $staffUserId;
            $rma->save();

            return $rma->load('lines');
        });
    }

    private function assertDiminished(Rma $rma, RmaLine $line, int $lineNo, int $diminished, ?string $reason, int $refundableQty): void
    {
        if ($diminished === 0) {
            return;
        }
        if ($rma->company_id !== null || $rma->return_reason !== 'consumer_cancellation') {
            throw new ReturnActionRefusedException('diminished_not_allowed', "Line {$lineNo}: a deduction for diminished value applies only to a consumer's cancellation, never to faulty goods.");
        }
        if ($diminished < 0) {
            throw new ReturnActionRefusedException('diminished_invalid', "Line {$lineNo}: the deduction cannot be negative.");
        }
        if ($reason === null || $reason === '') {
            throw new ReturnActionRefusedException('diminished_reason_required', "Line {$lineNo}: give the reason for the deduction.");
        }
        $goods = Money::roundHalfUpDiv($line->line_goods_net_minor * min($refundableQty, $line->requested_base_qty), max(1, $line->requested_base_qty));
        if ($diminished > $goods) {
            throw new ReturnActionRefusedException('diminished_exceeds_goods', "Line {$lineNo}: the deduction cannot be more than the goods being refunded.");
        }
    }

    /**
     * `return_in` movements and `stock_levels`, in the 02 §11.1 lock order.
     *
     * @param  list<array{sku_id: int, location_id: int, batch_id: int|null, qty: int, line_no: int}>  $restocks
     */
    private function restock(Rma $rma, array $restocks, int $staffUserId, CarbonImmutable $now): void
    {
        usort($restocks, fn (array $a, array $b) => [$a['sku_id'], $a['location_id'], $a['batch_id'] ?? -1] <=> [$b['sku_id'], $b['location_id'], $b['batch_id'] ?? -1]);

        foreach ($restocks as $r) {
            $movement = StockMovement::create([
                'occurred_at' => $now,
                'sku_id' => $r['sku_id'],
                'location_id' => $r['location_id'],
                'batch_id' => $r['batch_id'],
                'movement_type' => 'return_in',
                'base_qty' => $r['qty'],
                'reference_type' => 'rma',
                'reference_id' => $rma->id,
                'note' => "{$rma->rma_number} line {$r['line_no']}: restocked after inspection",
                'actor_user_id' => $staffUserId,
            ]);

            DB::statement(<<<'SQL'
                INSERT INTO stock_levels (sku_id, location_id, batch_id, on_hand_base_qty, last_movement_id, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT ON CONSTRAINT stock_levels_identity_uq DO UPDATE SET
                  on_hand_base_qty = stock_levels.on_hand_base_qty + EXCLUDED.on_hand_base_qty,
                  version          = stock_levels.version + 1,
                  last_movement_id = EXCLUDED.last_movement_id,
                  updated_at       = EXCLUDED.updated_at
                SQL, [$r['sku_id'], $r['location_id'], $r['batch_id'], $r['qty'], $movement->id, $now]);
        }
    }
}
