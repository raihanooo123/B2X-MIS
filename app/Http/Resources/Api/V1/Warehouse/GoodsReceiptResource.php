<?php

namespace App\Http\Resources\Api\V1\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Domain\Warehouse\ExpiryPolicy;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Pack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A goods receipt as the goods-in screen needs it (05.5 §4.2): what is
 * expected (the PO lines, ordered versus received so far) and what has
 * been booked on this receipt.
 *
 * **No cost figure of any kind** (CLAUDE.md invariant 9; 07 §6.2). The
 * warehouse counts goods; `costed` says only whether a line carried a
 * cost, which is what purchasing's review needs to know (05.5 §4.5).
 *
 * ULIDs only for ids a client passes back (06 §2): receipts and SKUs by
 * `public_id`. PO lines and packs have none, so they are addressed as on
 * paper — PO number and line number, pack code.
 *
 * @property GoodsReceipt $resource
 */
class GoodsReceiptResource extends JsonResource
{
    public function __construct(GoodsReceipt $receipt)
    {
        parent::__construct($receipt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $receipt = $this->resource;
        $receipt->loadMissing(['location', 'purchaseOrder', 'container']);

        $pos = $this->purchaseOrders($receipt);
        $expected = PurchaseOrderLine::query()
            ->whereIn('purchase_order_id', $pos->keys())
            ->with(['sku.product', 'sku.packs', 'pack'])
            ->orderBy('purchase_order_id')
            ->orderBy('line_no')
            ->get();

        $lines = GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receipt->id)
            ->with(['sku.product', 'pack', 'batch', 'bin', 'purchaseOrderLine'])
            ->orderByDesc('id')
            ->get();

        $prefillDate = ExpiryPolicy::receiptDate();
        $policy = new ExpiryPolicy;

        return [
            'id' => $receipt->public_id,
            'source' => $receipt->source,
            'status' => $receipt->status,
            'reference' => $receipt->purchaseOrder->po_number ?? $receipt->container?->container_ref,
            'location' => ['code' => $receipt->location?->code, 'name' => $receipt->location?->name],
            'opened_at' => $receipt->opened_at->toIso8601String(),
            'closed_at' => $receipt->closed_at?->toIso8601String(),
            'expected_lines' => $expected->map(fn (PurchaseOrderLine $l) => [
                'po_number' => $pos->get($l->purchase_order_id)?->po_number,
                'line_no' => $l->line_no,
                'sku' => self::sku($l->sku, $policy, $prefillDate),
                'pack_code' => $l->pack?->code,
                'pack_label' => $l->pack?->label,
                'pack_base_units' => $l->pack_base_units,
                'ordered_pack_qty' => $l->pack_qty,
                'ordered_base_qty' => $l->base_qty,
                'received_base_qty' => $l->received_base_qty,
                'outstanding_base_qty' => max(0, $l->base_qty - $l->received_base_qty),
                'variance_reason' => $l->variance_reason,
            ])->values()->all(),
            'lines' => $lines->map(fn (GoodsReceiptLine $l) => [
                'key' => $l->client_token,
                'received_at' => $l->received_at->toIso8601String(),
                'po_number' => $l->purchaseOrderLine === null ? null : $pos->get($l->purchaseOrderLine->purchase_order_id)?->po_number,
                'line_no' => $l->purchaseOrderLine?->line_no,
                'sku_code' => $l->sku?->sku_code,
                'name' => $l->sku?->product?->name,
                'pack_label' => $l->pack?->label,
                'pack_qty' => $l->pack_qty,
                'base_qty' => $l->base_qty,
                'batch_code' => $l->batch?->batch_code,
                'expires_on' => $l->batch?->expires_on?->toDateString(),
                'bin_code' => $l->bin?->code,
                'serial_count' => $l->sku !== null && TrackingMode::from($l->sku->tracking_mode)->tracksSerial() ? $l->base_qty : 0,
                'costed' => $l->sku_cost_id !== null,
            ])->values()->all(),
        ];
    }

    /**
     * A SKU as goods-in captures it (05.5 §4.3): its tracking decides the
     * fields shown, `expiry_prefill` is today plus `shelf_life_days`.
     *
     * @return array<string, mixed>|null
     */
    public static function sku(?Sku $sku, ExpiryPolicy $policy, ?CarbonImmutable $receiptDate = null): ?array
    {
        if ($sku === null) {
            return null;
        }

        $sku->loadMissing(['product', 'packs']);

        return [
            'id' => $sku->public_id,
            'sku_code' => $sku->sku_code,
            'name' => $sku->product?->name,
            'barcode' => $sku->barcode_ean,
            'tracking_mode' => $sku->tracking_mode,
            'requires_expiry' => (bool) $sku->requires_expiry,
            'expiry_prefill' => $policy->prefill($sku, $receiptDate ?? ExpiryPolicy::receiptDate())?->toDateString(),
            'packs' => $sku->packs->sortBy('base_units')->map(fn (Pack $p) => [
                'code' => $p->code,
                'label' => $p->label,
                'base_units' => $p->base_units,
                'barcode' => $p->barcode,
            ])->values()->all(),
        ];
    }

    /**
     * The POs this receipt receives against: its own, or every PO on its
     * container. Keyed by id.
     *
     * @return Collection<int, PurchaseOrder>
     */
    private function purchaseOrders(GoodsReceipt $receipt): Collection
    {
        return match (true) {
            $receipt->purchase_order_id !== null => PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->get()->keyBy('id'),
            $receipt->container_id !== null => PurchaseOrder::query()->where('container_id', $receipt->container_id)->get()->keyBy('id'),
            default => new Collection,
        };
    }
}
