<?php

namespace App\Domain\Collection;

use App\Models\Address;
use App\Models\Company;
use App\Models\OrderAddress;

/**
 * 05.6 §7A.2 step 2 (Q-C1): a trade collection has no delivery address; the
 * company's default billing address (02 §4.5) is snapshotted onto the order
 * as `billing`, as for any trade order. A `billing` default wins over a
 * `both` default. Public customers and guests need none.
 */
final class CollectionBillingAddress
{
    public static function for(int $companyId): ?Address
    {
        return Address::query()
            ->where('company_id', $companyId)
            ->whereIn('address_type', ['billing', 'both'])
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->orderByRaw("address_type = 'billing' DESC")
            ->orderBy('id')
            ->first();
    }

    /** Inside the placement transaction; preview has already refused a company without one. */
    public static function snapshot(int $orderId, int $companyId): void
    {
        $billing = self::for($companyId)
            ?? throw new \RuntimeException("Company {$companyId} has no default billing address (05.6 §7A.2).");

        OrderAddress::query()->create([
            'order_id' => $orderId,
            'address_type' => 'billing',
            'contact_name' => $billing->getAttribute('contact_name'),
            'phone' => $billing->getAttribute('phone'),
            'company_name' => Company::query()->where('id', $companyId)->value('name'),
            'line1' => $billing->getAttribute('line1'),
            'line2' => $billing->getAttribute('line2'),
            'city' => $billing->getAttribute('city'),
            'county' => $billing->getAttribute('county'),
            'postcode' => $billing->getAttribute('postcode'),
            'country_code' => trim((string) $billing->getAttribute('country_code')),
        ]);
    }
}
