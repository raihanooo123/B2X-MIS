<?php

namespace App\Domain\Delivery;

/** Where an order is going, as rating needs it: postcode and country. */
final readonly class DeliveryDestination
{
    public function __construct(
        public string $postcode,
        public string $countryCode,
    ) {}
}
