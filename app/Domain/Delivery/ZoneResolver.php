<?php

namespace App\Domain\Delivery;

use App\Models\DeliveryZone;
use App\Models\DeliveryZonePostcode;

/**
 * 05.6 §4.4 — postcode → delivery zone.
 *
 * Rules are an area plus an optional district range; the narrowest rule
 * that matches wins (`specificity`, a stored generated column, 02 §20.1:
 * range width, or 9999 for a whole-area rule), read through
 * `delivery_zone_postcodes_resolve_idx`. So `PO31` resolves to the Isle of
 * Wight's `PO30`–`PO41` range, and `PO3` — outside that range, with no `PO`
 * rule of its own — falls through to the mainland.
 *
 *   - UK addresses, and the Crown Dependencies with UK-format postcodes
 *     (IM, JE, GY — country GB, IM, JE or GG), resolve by postcode; no
 *     match → `GB_MAINLAND` (the default fallback). A postcode outside the
 *     UK's postcode areas is also flagged as not recognised — never blocked.
 *   - Anywhere else resolves on the zone's `country_code` (05.6 §4.4); a
 *     country with no zone yields no zone at all.
 */
final class ZoneResolver
{
    public const MAINLAND = 'GB_MAINLAND';

    private const POSTCODE_COUNTRIES = ['GB', 'IM', 'JE', 'GG'];

    /**
     * The UK's geographic postcode areas, including the Crown Dependencies
     * (GY, JE, IM). A postcode in one of these that matches no rule is an
     * ordinary mainland address; one outside them is "not recognised at
     * all" and is flagged for review (05.6 §10) — still delivered to.
     */
    private const UK_AREAS = [
        'AB', 'AL', 'B', 'BA', 'BB', 'BD', 'BH', 'BL', 'BN', 'BR', 'BS', 'BT', 'CA', 'CB', 'CF', 'CH', 'CM', 'CO', 'CR', 'CT', 'CV', 'CW',
        'DA', 'DD', 'DE', 'DG', 'DH', 'DL', 'DN', 'DT', 'DY', 'E', 'EC', 'EH', 'EN', 'EX', 'FK', 'FY', 'G', 'GL', 'GU', 'GY', 'HA', 'HD',
        'HG', 'HP', 'HR', 'HS', 'HU', 'HX', 'IG', 'IM', 'IP', 'IV', 'JE', 'KA', 'KT', 'KW', 'KY', 'L', 'LA', 'LD', 'LE', 'LL', 'LN', 'LS',
        'LU', 'M', 'ME', 'MK', 'ML', 'N', 'NE', 'NG', 'NN', 'NP', 'NR', 'NW', 'OL', 'OX', 'PA', 'PE', 'PH', 'PL', 'PO', 'PR', 'RG', 'RH',
        'RM', 'S', 'SA', 'SE', 'SG', 'SK', 'SL', 'SM', 'SN', 'SO', 'SP', 'SR', 'SS', 'ST', 'SW', 'SY', 'TA', 'TD', 'TF', 'TN', 'TQ', 'TR',
        'TS', 'TW', 'UB', 'W', 'WA', 'WC', 'WD', 'WF', 'WN', 'WR', 'WS', 'WV', 'YO', 'ZE',
    ];

    public function resolve(string $postcode, string $countryCode): ZoneResolution
    {
        $countryCode = strtoupper(trim($countryCode));

        if (! in_array($countryCode, self::POSTCODE_COUNTRIES, true)) {
            $zone = DeliveryZone::query()->where('country_code', $countryCode)->where('status', 'active')->orderBy('position')->first();

            return new ZoneResolution($zone, $zone !== null);
        }

        $parsed = Postcode::parse($postcode);
        if ($parsed === null) {
            // Not a postcode at all: the mainland, flagged (05.6 §4.4).
            return $this->mainland(false);
        }

        $zoneId = DeliveryZonePostcode::query()
            ->where('area', $parsed->area)
            ->where(fn ($q) => $q->whereNull('district_from')
                ->orWhere(fn ($q) => $q->where('district_from', '<=', $parsed->district)->where('district_to', '>=', $parsed->district)))
            ->orderBy('specificity')
            ->value('delivery_zone_id');

        $zone = $zoneId === null ? null : DeliveryZone::query()->where('status', 'active')->whereKey($zoneId)->first();
        if ($zone !== null) {
            return new ZoneResolution($zone, true);
        }

        // No rule: the mainland (05.6 §4.4). Flagged only when the postcode
        // is not a real UK one at all.
        return $this->mainland(in_array($parsed->area, self::UK_AREAS, true));
    }

    private function mainland(bool $recognised): ZoneResolution
    {
        return new ZoneResolution(DeliveryZone::query()->where('code', self::MAINLAND)->first(), $recognised);
    }
}
