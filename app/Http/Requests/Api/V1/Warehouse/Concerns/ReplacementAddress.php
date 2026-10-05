<?php

namespace App\Http\Requests\Api\V1\Warehouse\Concerns;

use App\Domain\Ordering\DeliveryAddress;

/**
 * 05.4 §14.2 R10: a replacement goes to the original delivery address
 * unless staff give another (the customer may have moved). Optional; the
 * same fields and limits as checkout's delivery address (PlaceOrderRequest).
 */
trait ReplacementAddress
{
    /** @return array<string, list<string>> */
    protected function replacementAddressRules(): array
    {
        return [
            'replacement_address' => ['nullable', 'array'],
            'replacement_address.contact_name' => ['required_with:replacement_address', 'string', 'max:191'],
            'replacement_address.company_name' => ['nullable', 'string', 'max:191'],
            'replacement_address.phone' => ['nullable', 'string', 'max:32'],
            'replacement_address.line1' => ['required_with:replacement_address', 'string', 'max:191'],
            'replacement_address.line2' => ['nullable', 'string', 'max:191'],
            'replacement_address.city' => ['required_with:replacement_address', 'string', 'max:100'],
            'replacement_address.county' => ['nullable', 'string', 'max:100'],
            'replacement_address.postcode' => ['required_with:replacement_address', 'string', 'max:16'],
            'replacement_address.country_code' => ['required_with:replacement_address', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }

    public function replacementAddress(): ?DeliveryAddress
    {
        $a = $this->validated('replacement_address');
        if (! is_array($a) || $a === []) {
            return null;
        }
        $opt = fn (string $key): ?string => isset($a[$key]) && is_string($a[$key]) && trim($a[$key]) !== '' ? trim($a[$key]) : null;

        return new DeliveryAddress(
            contactName: trim((string) $a['contact_name']),
            companyName: $opt('company_name'),
            phone: $opt('phone'),
            line1: trim((string) $a['line1']),
            line2: $opt('line2'),
            city: trim((string) $a['city']),
            county: $opt('county'),
            postcode: strtoupper(trim((string) $a['postcode'])),
            countryCode: strtoupper((string) $a['country_code']),
        );
    }
}
