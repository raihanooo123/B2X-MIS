<?php

namespace App\Domain\Pricing;

use Illuminate\Support\Facades\DB;
use Locale;

/**
 * The countries an order can be delivered to: those with a VAT rate in
 * force (`tax_rates`, 02 §6.5). Anywhere else, checkout could not work out
 * the tax (TaxRateResolver would refuse), so it is not offered.
 * Channel Islands and Isle of Man handling is manual (05.6 §4.2) and
 * appears here only if rates for them have been configured.
 */
final class DeliveryCountries
{
    /**
     * @return list<array{code: string, name: string}> United Kingdom first, then by name
     */
    public static function available(): array
    {
        $codes = DB::table('tax_rates')
            ->whereRaw('validity @> now()')
            ->distinct()
            ->orderBy('country_code')
            ->pluck('country_code')
            ->map(fn ($code) => trim((string) $code))
            ->all();

        $countries = array_map(fn (string $code) => ['code' => $code, 'name' => self::name($code)], $codes);

        usort($countries, fn (array $a, array $b) => [$a['code'] !== 'GB', $a['name']] <=> [$b['code'] !== 'GB', $b['name']]);

        return $countries;
    }

    public static function name(string $code): string
    {
        $name = Locale::getDisplayRegion('-'.$code, 'en_GB');

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }
}
