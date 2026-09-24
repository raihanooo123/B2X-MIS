<?php

namespace App\Domain\Ordering;

/**
 * The delivery address snapshotted onto `order_addresses` at checkout
 * (02 §8.4: an order never foreign-keys to `addresses`, so editing or
 * deleting a saved address can never change where a past order went).
 */
final readonly class DeliveryAddress
{
    public function __construct(
        public string $contactName,
        public ?string $companyName,
        public ?string $phone,
        public string $line1,
        public ?string $line2,
        public string $city,
        public ?string $county,
        public string $postcode,
        public string $countryCode,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'contact_name' => $this->contactName,
            'company_name' => $this->companyName,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'county' => $this->county,
            'postcode' => $this->postcode,
            'country_code' => $this->countryCode,
        ];
    }
}
