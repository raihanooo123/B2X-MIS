<?php

namespace App\Http\Resources\Api\V1\Warehouse;

use App\Domain\Catalogue\TrackingMode;
use App\Domain\Warehouse\PickLine;
use App\Domain\Warehouse\PickList;
use App\Models\Company;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\ShipmentLine;
use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * A shipment's pick list for the picking and dispatch screens (05.5 §5.2,
 * §7): the lines in walk order, each with its batch, expiry and reserved
 * serials, what a line may be substituted with, and the order's progress
 * — what this shipment carries and what will remain.
 *
 * **No price or cost of any kind** (CLAUDE.md invariant 9). ULIDs for the
 * shipment; a line is addressed by `line_no` and `batch_code`
 * (PickLineRequest). `barcodes` lets the screen match a scanned SKU or
 * case barcode to its line without a round trip.
 *
 * @property PickList $resource
 */
class PickListResource extends JsonResource
{
    public function __construct(PickList $pickList)
    {
        parent::__construct($pickList);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $list = $this->resource;
        $shipment = $list->shipment;
        $order = $list->order;
        $skuIds = array_values(array_unique(array_map(fn (PickLine $l) => $l->skuId, $list->lines)));
        $barcodes = $this->barcodes($skuIds);

        $onThisShipment = [];
        foreach ($list->lines as $line) {
            $onThisShipment[$line->orderLineId] = ($onThisShipment[$line->orderLineId] ?? 0) + $line->baseQty;
        }
        if ($shipment->status === 'dispatched') {
            $onThisShipment = ShipmentLine::query()->where('shipment_id', $shipment->id)->pluck('dispatched_base_qty', 'order_line_id')->map(fn ($q) => (int) $q)->all();
        }

        $customer = $order->company_id === null ? null : Company::query()->whereKey($order->company_id)->value('name');

        return [
            'shipment' => [
                'id' => $shipment->public_id,
                'status' => $shipment->status,
                'fulfilment_type' => $shipment->fulfilment_type,
                'location_code' => Location::query()->whereKey($shipment->location_id)->value('code'),
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'parcel_count' => $shipment->parcel_count,
                'dispatched_at' => $shipment->dispatched_at?->toIso8601String(),
            ],
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'customer' => $customer,
                'customer_reference' => $order->customer_reference,
            ],
            'complete' => $list->isComplete(),
            'lines' => array_map(fn (PickLine $l) => [
                'line_no' => $l->lineNo,
                'sku_code' => $l->skuCode,
                'name' => $l->name,
                'thumbnail_url' => $l->thumbnailUrl,
                'barcodes' => $barcodes[$l->skuId] ?? [],
                'pack_label' => $l->packLabel,
                'pack_base_units' => $l->packBaseUnits,
                'base_qty' => $l->baseQty,
                'packs' => $l->packs(),
                'loose_units' => $l->looseUnits(),
                'tracking_mode' => $l->trackingMode,
                'batch_code' => $l->batchCode,
                'expires_on' => $l->expiresOn,
                'bin_code' => $l->binCode,
                'status' => $l->status,
                'serials' => array_map(fn (array $s) => ['serial_number' => $s['serial_number'], 'picked' => $s['picked']], $l->serials),
                'substitutes' => $this->substitutes($l, $shipment->location_id),
            ], $list->lines),
            'order_lines' => OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get()->map(fn (OrderLine $l) => [
                'line_no' => $l->line_no,
                'sku_code' => $l->sku_code_snapshot,
                'name' => $l->name_snapshot,
                'pack_label' => $l->pack_label_snapshot,
                'pack_base_units' => $l->pack_base_units,
                'ordered_base_qty' => $l->base_qty,
                'dispatched_base_qty' => (int) $l->getAttribute('dispatched_base_qty'),
                'on_this_shipment_base_qty' => $onThisShipment[$l->id] ?? 0,
            ])->values()->all(),
        ];
    }

    /**
     * Batches a batch-tracked, not serial-tracked, still-unpicked line
     * could be substituted with (05.5 §5.3): other active batches of the
     * SKU at this location with enough available. The server re-checks.
     *
     * @return list<array{batch_code: string, expires_on: ?string, available_base_qty: int}>
     */
    private function substitutes(PickLine $line, int $locationId): array
    {
        $mode = TrackingMode::from($line->trackingMode);
        if ($line->status !== 'allocated' || ! $mode->tracksBatch() || $mode->tracksSerial()) {
            return [];
        }

        return array_values(DB::table('stock_levels')
            ->join('batches', 'batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.sku_id', $line->skuId)
            ->where('stock_levels.location_id', $locationId)
            ->where('batches.status', 'active')
            ->where('stock_levels.batch_id', '<>', $line->batchId)
            ->where('stock_levels.available_base_qty', '>=', $line->baseQty)
            ->orderByRaw('batches.expires_on ASC NULLS LAST')
            ->orderBy('batches.id')
            ->limit(10)
            ->get(['batches.batch_code', 'batches.expires_on', 'stock_levels.available_base_qty'])
            ->map(fn ($r) => [
                'batch_code' => (string) $r->batch_code,
                'expires_on' => $r->expires_on === null ? null : substr((string) $r->expires_on, 0, 10),
                'available_base_qty' => (int) $r->available_base_qty,
            ])
            ->all());
    }

    /**
     * @param  list<int>  $skuIds
     * @return array<int, list<string>>
     */
    private function barcodes(array $skuIds): array
    {
        $codes = [];
        foreach (Sku::query()->whereIn('id', $skuIds)->get(['id', 'sku_code', 'barcode_ean']) as $sku) {
            $codes[$sku->id] = array_values(array_filter([$sku->sku_code, $sku->barcode_ean]));
        }
        foreach (Pack::query()->whereIn('sku_id', $skuIds)->whereNotNull('barcode')->get(['sku_id', 'barcode']) as $pack) {
            $codes[$pack->sku_id][] = (string) $pack->barcode;
        }

        return $codes;
    }
}
