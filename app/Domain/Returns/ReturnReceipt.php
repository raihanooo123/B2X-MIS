<?php

namespace App\Domain\Returns;

use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\Rma;
use App\Models\RmaLine;
use Illuminate\Support\Facades\DB;

/**
 * 05.4 §7.3 — the warehouse books a returned parcel in against its RMA.
 *
 *   - per line: what arrived, which may differ from what was requested,
 *     up to `rma_lines_received_chk` (double the request);
 *   - batch: recovered from the dispatch trace of the originating order
 *     line (`shipment_line_batches`) when exactly one batch went out on it;
 *     otherwise left unknown, and the line cannot be restocked at
 *     inspection (S6d) — never guessed;
 *   - `received_at` and `status = 'received'`, and the refund deadline
 *     recomputed (05.4 §13.5: the earlier of receipt and proof).
 *
 * **No stock movement is written at receipt.** The goods are present but
 * not sellable; `return_in` is written only when inspection restocks them.
 * Serial scanning (§7.3 step 3) waits for serial-tracked checkout, which
 * cannot sell serials yet.
 */
final class ReturnReceipt
{
    /**
     * @param  array<int, int>  $receivedBaseQtyByLineNo  RMA line_no => base units that arrived
     *
     * @throws ReturnActionRefusedException
     */
    public function receive(int $rmaId, array $receivedBaseQtyByLineNo, int $staffUserId): Rma
    {
        return DB::transaction(function () use ($rmaId, $receivedBaseQtyByLineNo, $staffUserId): Rma {
            $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
            $late = $rma->company_id === null && $rma->status === 'resolved' && $rma->resolution_type === 'credit_note' && $rma->goods_sent_at !== null && $rma->received_at === null;
            if ($rma->status !== 'awaiting_goods' && ! $late) {
                throw new ReturnActionRefusedException('not_awaiting_goods', "Return {$rma->rma_number} is not waiting for goods (it is {$rma->status}).");
            }

            $lines = RmaLine::query()->where('rma_id', $rma->id)->orderBy('line_no')->lockForUpdate()->get()->keyBy('line_no');
            $anything = false;

            foreach ($receivedBaseQtyByLineNo as $lineNo => $qty) {
                /** @var RmaLine|null $line */
                $line = $lines->get($lineNo);
                if ($line === null) {
                    throw new ReturnActionRefusedException('unknown_line', "Line {$lineNo} is not on return {$rma->rma_number}.");
                }
                if ($qty < 0 || $qty > $line->requested_base_qty * 2) {
                    throw new ReturnActionRefusedException('quantity_out_of_range', "Line {$lineNo}: enter between 0 and ".($line->requested_base_qty * 2).' units.');
                }

                $line->received_base_qty = $qty;
                $line->batch_id = $qty > 0 ? self::recoveredBatch($line->order_line_id) : null;
                $line->save();
                $anything = $anything || $qty > 0;
            }

            if (! $anything) {
                throw new ReturnActionRefusedException('nothing_received', 'Enter what arrived on at least one line.');
            }

            if (! $late) {
                $rma->status = 'received';
            }
            $rma->received_at = now();
            $rma->handled_by_user_id = $staffUserId;
            RefundDeadline::apply($rma);
            $rma->save();

            return $rma->load('lines');
        });
    }

    /** The one batch dispatched on this order line, or null when none or several (05.4 §7.3 step 4). */
    private static function recoveredBatch(int $orderLineId): ?int
    {
        $batches = DB::table('shipment_line_batches')
            ->join('shipment_lines', 'shipment_lines.id', '=', 'shipment_line_batches.shipment_line_id')
            ->where('shipment_lines.order_line_id', $orderLineId)
            ->distinct()
            ->pluck('shipment_line_batches.batch_id');

        return $batches->count() === 1 ? (int) $batches->first() : null;
    }
}
