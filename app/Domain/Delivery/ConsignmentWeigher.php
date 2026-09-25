<?php

namespace App\Domain\Delivery;

use App\Models\CartLine;
use App\Models\SystemConfiguration;

/**
 * 05.6 §5.1 — how heavy a consignment is, and whether it goes as parcels
 * or on a pallet.
 *
 *   line_weight_g        = pack_qty × packs.gross_weight_g
 *                          (fallback: base_qty × skus.unit_weight_g)
 *   consignment_weight_g = Σ line_weight_g
 *
 * A line with neither weight makes the whole consignment unrateable: a
 * carriage figure invented from missing data is worse than asking.
 *
 *   pallet_equivalents = Σ pack_qty / (packs_per_layer × layers_per_pallet)
 *   method = pallet_equivalents ≥ threshold (default 0.5)
 *            OR weight > max parcel weight (default 30 kg) ? pallet : parcel
 *
 * Integer arithmetic only: pallet equivalents are summed in millionths
 * (each line's share floored), and the threshold is configured in basis
 * points. A line whose pack has no pallet pattern contributes nothing to
 * the pallet count — the weight rule still applies to it. Both thresholds
 * are `system_configurations` (company, then global, then the defaults).
 */
final class ConsignmentWeigher
{
    public const PALLET_THRESHOLD_KEY = 'delivery.pallet_threshold_bp';

    public const MAX_PARCEL_WEIGHT_KEY = 'delivery.max_parcel_weight_g';

    public const DEFAULT_PALLET_THRESHOLD_BP = 5000;

    public const DEFAULT_MAX_PARCEL_WEIGHT_G = 30000;

    /**
     * @param  list<ConsignmentLine>  $lines
     */
    public function weigh(array $lines, ?int $companyId = null): Consignment
    {
        $weightG = 0;
        $palletPpm = 0;
        $missing = false;

        foreach ($lines as $line) {
            if ($line->packGrossWeightG !== null) {
                $weightG += $line->packQty * $line->packGrossWeightG;
            } elseif ($line->skuUnitWeightG !== null) {
                $weightG += $line->baseQty * $line->skuUnitWeightG;
            } else {
                $missing = true;
            }

            $perPallet = ($line->packsPerLayer ?? 0) * ($line->layersPerPallet ?? 0);
            if ($perPallet > 0) {
                $palletPpm += intdiv($line->packQty * 1_000_000, $perPallet);
            }
        }

        $thresholdPpm = $this->config(self::PALLET_THRESHOLD_KEY, $companyId, self::DEFAULT_PALLET_THRESHOLD_BP) * 100;
        $maxParcelG = $this->config(self::MAX_PARCEL_WEIGHT_KEY, $companyId, self::DEFAULT_MAX_PARCEL_WEIGHT_G);

        $pallet = $palletPpm >= $thresholdPpm || (! $missing && $weightG > $maxParcelG);

        return new Consignment($missing ? null : $weightG, $pallet ? 'pallet' : 'parcel', $palletPpm);
    }

    /**
     * @param  iterable<CartLine>  $cartLines  with `pack` and `sku` loaded
     * @return list<ConsignmentLine>
     */
    public static function linesFromCart(iterable $cartLines): array
    {
        $lines = [];
        foreach ($cartLines as $line) {
            $pack = $line->pack;
            $sku = $line->sku;
            $lines[] = new ConsignmentLine(
                packQty: (int) $line->pack_qty,
                baseQty: (int) $line->base_qty,
                packGrossWeightG: $pack?->gross_weight_g === null ? null : (int) $pack->gross_weight_g,
                skuUnitWeightG: $sku?->unit_weight_g === null ? null : (int) $sku->unit_weight_g,
                packsPerLayer: $pack?->packs_per_layer === null ? null : (int) $pack->packs_per_layer,
                layersPerPallet: $pack?->layers_per_pallet === null ? null : (int) $pack->layers_per_pallet,
            );
        }

        return $lines;
    }

    private function config(string $key, ?int $companyId, int $default): int
    {
        $rows = SystemConfiguration::query()
            ->where('config_key', $key)
            ->where(fn ($q) => $q->where('scope', 'global')->when($companyId !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope', 'company')->where('company_id', $companyId))))
            ->get(['scope', 'value_int'])
            ->keyBy('scope');

        $row = $rows->get('company') ?? $rows->get('global');

        return $row->value_int ?? $default;
    }
}
