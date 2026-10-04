<?php

namespace App\Domain\Returns;

use App\Domain\Notifications\Notifications;
use App\Domain\Pricing\Money;
use App\Domain\Reference\NumberSequenceService;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Rma;
use App\Models\RmaLine;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 05.4 §13.4 — a consumer reports faulty, damaged or wrong goods (CRA;
 * 05.15 §7.3 "Report a problem").
 *
 *   - **Never blocked by a window**, and `is_refundable` never applies — not
 *     even `bespoke`: a faulty item is the trader's problem whatever it is.
 *     Only the quantity already held by other returns limits it.
 *   - Not automatic: the return is `requested` until a handler approves it
 *     (we collect, at our cost) or rejects it with a reason.
 *   - Within 30 days of possession (CRA s.22, the short-term right to
 *     reject): a full refund, no deduction, and the whole delivery when the
 *     whole order is rejected (ReturnResolution). After 30 days the handler
 *     offers repair or replacement first.
 *
 * Photographs go on the return as attachments, on the private disk.
 */
final class FaultReports
{
    /** @var list<string> */
    public const REASONS = ['damaged', 'faulty', 'wrong_item', 'wrong_quantity', 'not_as_described', 'shipping_damage', 'expired_on_arrival'];

    /** Days of the short-term right to reject (CRA s.22). Law, not configuration. */
    public const REJECT_DAYS = 30;

    public function __construct(
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /** Whether a fault return was reported within 30 days of possession; in the buyer's favour when possession is not known. */
    public static function withinRejectPeriod(Rma $rma): bool
    {
        if ($rma->possession_on === null) {
            return true;
        }

        // Compared as UK calendar dates (Y-m-d), never as instants in two timezones.
        $reported = DisplayTime::local($rma->requested_at)->toDateString();
        $lastDay = CarbonImmutable::parse($rma->possession_on->toDateString())->addDays(self::REJECT_DAYS)->toDateString();

        return $reported <= $lastDay;
    }

    /**
     * Lines that can be reported, with how many packs of each.
     *
     * @return array<int, array{order_line: OrderLine, reportable_pack_qty: int}> keyed by line_no
     */
    public static function reportable(Order $order): array
    {
        if ($order->company_id !== null || ! in_array($order->status, ['part_dispatched', 'dispatched', 'completed'], true)) {
            return [];
        }

        $out = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get() as $line) {
            $packs = intdiv(max(0, $line->dispatched_base_qty - CancellationEligibility::heldBaseQty($line->id)), max(1, $line->pack_base_units));
            $out[$line->line_no] = ['order_line' => $line, 'reportable_pack_qty' => $packs];
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $packQtyByLineNo
     * @param  list<UploadedFile>  $photos
     *
     * @throws CancellationRequestRejectedException
     */
    public function report(int $orderId, array $packQtyByLineNo, string $reason, string $detail, array $photos, CarbonImmutable $at, ?int $userId): Rma
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new CancellationRequestRejectedException('unknown_reason', 'Choose what is wrong with the items.', 'reason');
        }
        $wanted = array_filter($packQtyByLineNo, fn (int $qty) => $qty > 0);
        if ($wanted === []) {
            throw new CancellationRequestRejectedException('nothing_selected', 'Choose at least one item.', 'lines');
        }

        $disk = (string) config('filesystems.default');
        $stored = [];
        foreach ($photos as $photo) {
            $path = $photo->storeAs('rma-photos', Str::ulid().'.'.strtolower($photo->getClientOriginalExtension() ?: 'bin'), $disk);
            if ($path !== false) {
                $stored[] = [$photo, $path];
            }
        }

        try {
            return DB::transaction(function () use ($orderId, $wanted, $reason, $detail, $at, $userId, $disk, $stored): Rma {
                $order = Order::query()->lockForUpdate()->findOrFail($orderId);
                $reportable = self::reportable($order);
                if ($reportable === []) {
                    throw new CancellationRequestRejectedException('not_reportable', 'Problems can be reported once your order has been sent.');
                }

                $lines = [];
                foreach ($wanted as $lineNo => $packs) {
                    $line = $reportable[$lineNo] ?? null;
                    if ($line === null) {
                        throw new CancellationRequestRejectedException('unknown_line', 'That item is not on this order.', "lines.{$lineNo}");
                    }
                    if ($packs > $line['reportable_pack_qty']) {
                        throw new CancellationRequestRejectedException('quantity_exceeds_returnable', "You can report up to {$line['reportable_pack_qty']} of this item.", "lines.{$lineNo}");
                    }
                    $lines[] = ['order_line' => $line['order_line'], 'packs' => $packs];
                }

                $possession = PossessionDay::for($order);
                $rma = Rma::query()->create([
                    'rma_number' => (string) Str::ulid(),
                    'company_id' => null,
                    'order_id' => $order->id,
                    'requested_by_user_id' => $userId,
                    'status' => 'requested',
                    'return_reason' => $reason,
                    'reason_detail' => mb_substr(trim($detail), 0, 2000),
                    'carriage_payer' => 'us',
                    'restocking_rate_bp' => 0,
                    'restocking_minimum_minor' => 0,
                    'restocking_fee_minor' => 0,
                    'carriage_recharge_minor' => 0,
                    'possession_on' => $possession?->date->toDateString(),
                    'possession_basis' => $possession?->basis,
                    'requested_at' => $at,
                ]);

                $goods = 0;
                foreach ($lines as $i => ['order_line' => $line, 'packs' => $packs]) {
                    $baseQty = $packs * $line->pack_base_units;
                    $value = Money::roundHalfUpDiv($line->line_net_minor * $baseQty, $line->base_qty);
                    $goods += $value;
                    RmaLine::query()->create([
                        'rma_id' => $rma->id, 'line_no' => $i + 1, 'order_line_id' => $line->id, 'sku_id' => $line->sku_id, 'pack_id' => $line->pack_id,
                        'sku_code_snapshot' => $line->sku_code_snapshot, 'name_snapshot' => $line->name_snapshot,
                        'requested_pack_qty' => $packs, 'requested_base_qty' => $baseQty,
                        'unit_price_net_e4' => $line->unit_price_net_e4, 'tax_rate_bp' => $line->tax_rate_bp, 'line_goods_net_minor' => $value,
                    ]);
                }

                foreach ($stored as [$photo, $path]) {
                    Attachment::query()->create([
                        'attachable_type' => 'rma', 'attachable_id' => $rma->id, 'disk' => $disk, 'path' => $path,
                        'original_name' => mb_substr($photo->getClientOriginalName(), 0, 255),
                        'mime_type' => (string) ($photo->getMimeType() ?? 'application/octet-stream'),
                        'size_bytes' => (int) $photo->getSize(), 'is_customer_visible' => true, 'uploaded_by_user_id' => $userId,
                    ]);
                }

                $rma->update(['goods_net_minor' => $goods, 'rma_number' => $this->numbers->next('rma_number')]);
                $this->notifications->rmaRequested($rma->id);

                return $rma->load('lines');
            });
        } catch (\Throwable $e) {
            foreach ($stored as [, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }
    }

    /** The handler accepts the report; we collect the goods at our cost (05.4 §13.4). */
    public function approve(int $rmaId, int $staffUserId): Rma
    {
        return DB::transaction(function () use ($rmaId, $staffUserId): Rma {
            $rma = $this->lockedReport($rmaId);
            $rma->fill([
                'status' => 'awaiting_goods',
                'approved_at' => now(),
                'return_method' => 'collection',
                'carriage_payer' => 'us',
                'handled_by_user_id' => $staffUserId,
            ])->save();
            $this->notifications->rmaApproved($rma->id);

            return $rma;
        });
    }

    /** The handler does not accept the report, with the reason the customer is given. */
    public function reject(int $rmaId, int $staffUserId, string $reason): Rma
    {
        return DB::transaction(function () use ($rmaId, $staffUserId, $reason): Rma {
            $rma = $this->lockedReport($rmaId);
            $rma->fill(['status' => 'rejected', 'handled_by_user_id' => $staffUserId, 'internal_note' => mb_substr(trim($reason), 0, 2000)])->save();
            $this->notifications->rmaRejected($rma->id, $reason);

            return $rma;
        });
    }

    private function lockedReport(int $rmaId): Rma
    {
        $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
        if ($rma->status !== 'requested' || $rma->return_reason === 'consumer_cancellation') {
            throw new ReturnActionRefusedException('not_awaiting_review', "Return {$rma->rma_number} is not a problem report waiting for review.");
        }

        return $rma;
    }
}
