<?php

namespace Database\Seeders;

use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
use App\Models\DeliveryZonePostcode;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §4.2's eight launch zones, their postcode rules, and weight-banded
 * carriage rates (05.6 §5.2). Idempotent: zones by code; postcode rules
 * and active rates are rebuilt for each zone on every run.
 *
 * Rates are realistic placeholders for a UK wholesale carrier — the
 * client's actual carrier contracts replace them. Parcel bands step at
 * 2 / 10 / 20 kg with a per-kg surcharge above 20 kg; pallet bands step at
 * 250 / 500 kg with a per-kg surcharge above 500 kg. All net of VAT; the
 * carriage tax class is standard-rated (20% GB).
 *
 * Carriage-paid thresholds: the mainland inherits the configured £500
 * (05.6 §6); the Highlands use 05.6 §4.2's own £1,200 example; the other
 * rated zones carry placeholder thresholds for the client to confirm
 * (05.6 §13 Q1). Scilly and the Channel Islands are manual quote at launch
 * (§4.2) and have no rates.
 */
class DeliveryZoneSeeder extends Seeder
{
    /**
     * code => [name, mainland, manual quote, threshold (minor) or null, transit days, rules]
     * rules: [area, from, to] — from/to null for the whole area.
     */
    private const ZONES = [
        'GB_MAINLAND' => ['UK Mainland', true, false, null, 1, []],
        'GB_HIGHLANDS' => ['Scottish Highlands', true, false, 120000, 2, [['IV', null, null], ['KW', 1, 14], ['PA', 21, 40], ['PH', 15, 50]]],
        'GB_SCOT_ISLES' => ['Scottish Islands', false, false, 200000, 3, [['HS', null, null], ['ZE', null, null], ['KW', 15, 17], ['PA', 41, 80]]],
        'GB_NI' => ['Northern Ireland', false, false, 100000, 2, [['BT', null, null]]],
        'GB_IOW' => ['Isle of Wight', false, false, 100000, 2, [['PO', 30, 41]]],
        'GB_IOM' => ['Isle of Man', false, false, 150000, 3, [['IM', null, null]]],
        'GB_CHANNEL' => ['Channel Islands', false, true, null, null, [['JE', null, null], ['GY', null, null]]],
        'GB_SCILLY' => ['Isles of Scilly', false, true, null, null, [['TR', 21, 25]]],
    ];

    /**
     * code => [parcel bands, pallet bands]; a band is [from_g, to_g|null, price_net_minor, per_extra_kg_minor|null].
     */
    private const RATES = [
        'GB_MAINLAND' => [
            [[0, 2000, 650, null], [2000, 10000, 895, null], [10000, 20000, 1250, null], [20000, null, 1600, 45]],
            [[0, 250000, 4800, null], [250000, 500000, 6500, null], [500000, null, 8500, 4]],
        ],
        'GB_HIGHLANDS' => [
            [[0, 2000, 995, null], [2000, 10000, 1450, null], [10000, 20000, 1950, null], [20000, null, 2450, 75]],
            [[0, 250000, 8900, null], [250000, 500000, 11500, null], [500000, null, 14500, 8]],
        ],
        'GB_SCOT_ISLES' => [
            [[0, 2000, 1450, null], [2000, 10000, 2250, null], [10000, 20000, 3200, null], [20000, null, 3900, 120]],
            [[0, 250000, 14500, null], [250000, 500000, 18500, null], [500000, null, 23500, 12]],
        ],
        'GB_NI' => [
            [[0, 2000, 1250, null], [2000, 10000, 1850, null], [10000, 20000, 2600, null], [20000, null, 3200, 95]],
            [[0, 250000, 9500, null], [250000, 500000, 12500, null], [500000, null, 15500, 9]],
        ],
        'GB_IOW' => [
            [[0, 2000, 1150, null], [2000, 10000, 1650, null], [10000, 20000, 2300, null], [20000, null, 2800, 85]],
            [[0, 250000, 9900, null], [250000, 500000, 12900, null], [500000, null, 15900, 9]],
        ],
        'GB_IOM' => [
            [[0, 2000, 1350, null], [2000, 10000, 1950, null], [10000, 20000, 2750, null], [20000, null, 3400, 110]],
            [[0, 250000, 11500, null], [250000, 500000, 14500, null], [500000, null, 17500, 10]],
        ],
    ];

    public const CARRIAGE_TAX_CLASS = 'CARRIAGE';

    public function run(): void
    {
        DB::transaction(function () {
            $taxClass = TaxClass::query()->firstOrCreate(['code' => self::CARRIAGE_TAX_CLASS], ['name' => 'Carriage (standard rated)']);
            if (! TaxRate::query()->where('tax_class_id', $taxClass->id)->where('country_code', 'GB')->whereNull('region')->exists()) {
                TaxRate::query()->create(['tax_class_id' => $taxClass->id, 'country_code' => 'GB', 'rate_bp' => 2000]);
            }

            $position = 0;
            foreach (self::ZONES as $code => [$name, $mainland, $manual, $threshold, $transit, $rules]) {
                $zone = DeliveryZone::query()->updateOrCreate(['code' => $code], [
                    'name' => $name,
                    'status' => 'active',
                    'position' => $position++,
                    'country_code' => 'GB',
                    'is_mainland' => $mainland,
                    'is_serviceable' => true,
                    'requires_manual_quote' => $manual,
                    'carriage_paid_threshold_minor' => $threshold,
                    'transit_days' => $transit,
                ]);

                DeliveryZonePostcode::query()->where('delivery_zone_id', $zone->id)->delete();
                foreach ($rules as [$area, $from, $to]) {
                    DeliveryZonePostcode::query()->create(['delivery_zone_id' => $zone->id, 'area' => $area, 'district_from' => $from, 'district_to' => $to]);
                }

                DeliveryRate::query()->where('zone_id', $zone->id)->where('status', 'active')->update(['status' => 'archived']);
                foreach (self::RATES[$code] ?? [[], []] as $i => $bands) {
                    foreach ($bands as [$from, $to, $price, $perKg]) {
                        DeliveryRate::query()->create([
                            'zone_id' => $zone->id,
                            'method' => $i === 0 ? 'parcel' : 'pallet',
                            'weight_range' => '['.$from.','.($to ?? '').')',
                            'price_net_minor' => $price,
                            'per_extra_kg_minor' => $perKg,
                            'tax_class_id' => $taxClass->id,
                            'status' => 'active',
                        ]);
                    }
                }
            }
        });
    }
}
