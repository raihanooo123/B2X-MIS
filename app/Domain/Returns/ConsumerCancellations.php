<?php

namespace App\Domain\Returns;

use App\Domain\Notifications\Notifications;
use App\Domain\Pricing\Money;
use App\Domain\Reference\NumberSequenceService;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Models\Order;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 05.4 §13.3 — a consumer cancels some or all of a dispatched order, and
 * the return is approved at once: a statutory cancellation has no
 * discretion. `return_reason = 'consumer_cancellation'`, no fee
 * (`rmas_consumer_fee_chk`), and:
 *
 *   - `cancellation_notified_at`: when the customer told us — the request
 *     time online, or the time staff record (S6e);
 *   - `possession_on`, `possession_basis`: the day the window ran from,
 *     snapshotted so a later configuration change cannot move a deadline;
 *   - `return_by_date`: 14 days after they told us (reg. 35(4));
 *   - return method: they post it back at their cost, unless it is a
 *     pallet with no estimated return cost (02 §27), which we collect at
 *     ours — then the refund deadline is set now, 14 days after they told
 *     us (reg. 34(6), 05.4 §13.5).
 *
 * The order row is locked first, so two requests for one order cannot both
 * take the same units; eligibility is re-run under that lock. The RMA
 * number is taken last (02 §11.3). Each line's goods value is what was
 * charged for those units: the order line's net, pro rata, one half-up
 * rounding (03 §6) — exact when the whole line is returned.
 */
final class ConsumerCancellations
{
    public function __construct(
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /**
     * @param  array<int, int>  $packQtyByLineNo  order line_no => packs to cancel (zeros ignored)
     *
     * @throws CancellationRequestRejectedException
     */
    public function request(int $orderId, array $packQtyByLineNo, CarbonImmutable $notifiedAt, ?int $requestedByUserId = null): Rma
    {
        $wanted = array_filter($packQtyByLineNo, fn (int $qty) => $qty > 0);
        if ($wanted === []) {
            throw new CancellationRequestRejectedException('nothing_selected', 'Choose at least one item to cancel.', 'lines');
        }

        return DB::transaction(function () use ($orderId, $wanted, $notifiedAt, $requestedByUserId): Rma {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $eligibility = CancellationEligibility::for($order, $notifiedAt);

            if ($eligibility->possession === null || ! $eligibility->available) {
                throw new CancellationRequestRejectedException($eligibility->reason ?? 'not_available', $eligibility->message ?? 'This order cannot be cancelled online.');
            }

            $lines = [];
            foreach ($wanted as $lineNo => $packs) {
                $line = $eligibility->lines[$lineNo] ?? null;
                if ($line === null) {
                    throw new CancellationRequestRejectedException('unknown_line', 'That item is not on this order.', "lines.{$lineNo}");
                }
                if (! $line['eligible']) {
                    throw new CancellationRequestRejectedException('line_not_cancellable', (string) $line['refusal'], "lines.{$lineNo}");
                }
                if ($packs > $line['returnable_pack_qty']) {
                    throw new CancellationRequestRejectedException('quantity_exceeds_returnable', "You can cancel up to {$line['returnable_pack_qty']} of this item.", "lines.{$lineNo}");
                }
                $lines[] = ['order_line' => $line['order_line'], 'packs' => $packs];
            }

            $notifiedDay = DisplayTime::local($notifiedAt)->startOfDay();
            $weCollect = $order->delivery_method === 'pallet' && $order->return_cost_estimate_gross_minor === null;

            $rma = Rma::query()->create([
                // Placeholder, unique; replaced by the gapless number at the end.
                'rma_number' => (string) Str::ulid(),
                'company_id' => null,
                'order_id' => $order->id,
                'requested_by_user_id' => $requestedByUserId,
                'status' => 'awaiting_goods',
                'return_reason' => 'consumer_cancellation',
                'return_method' => $weCollect ? 'collection' : 'customer_carriage',
                'carriage_payer' => $weCollect ? 'us' : 'customer',
                'restocking_rate_bp' => 0,
                'restocking_minimum_minor' => 0,
                'restocking_fee_minor' => 0,
                'carriage_recharge_minor' => 0,
                'cancellation_notified_at' => $notifiedAt,
                'possession_on' => $eligibility->possession->date->toDateString(),
                'possession_basis' => $eligibility->possession->basis,
                'refund_due_on' => $weCollect ? $notifiedDay->addDays(14)->toDateString() : null,
                'requested_at' => $notifiedAt,
                'approved_at' => now(),
                'return_by_date' => $notifiedDay->addDays(14)->toDateString(),
            ]);

            $goods = 0;
            foreach ($lines as $i => ['order_line' => $line, 'packs' => $packs]) {
                $baseQty = $packs * $line->pack_base_units;
                $value = Money::roundHalfUpDiv($line->line_net_minor * $baseQty, $line->base_qty);
                $goods += $value;

                RmaLine::query()->create([
                    'rma_id' => $rma->id,
                    'line_no' => $i + 1,
                    'order_line_id' => $line->id,
                    'sku_id' => $line->sku_id,
                    'pack_id' => $line->pack_id,
                    'sku_code_snapshot' => $line->sku_code_snapshot,
                    'name_snapshot' => $line->name_snapshot,
                    'requested_pack_qty' => $packs,
                    'requested_base_qty' => $baseQty,
                    'unit_price_net_e4' => $line->unit_price_net_e4,
                    'tax_rate_bp' => $line->tax_rate_bp,
                    'line_goods_net_minor' => $value,
                ]);
            }

            // 02 §11.3: the gapless number last, so a rolled-back request takes none.
            $rma->update(['goods_net_minor' => $goods, 'rma_number' => $this->numbers->next('rma_number')]);
            $this->notifications->rmaApproved($rma->id);

            return $rma->load('lines');
        });
    }
}
