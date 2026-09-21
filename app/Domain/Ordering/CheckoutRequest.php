<?php

namespace App\Domain\Ordering;

/**
 * Input to CheckoutService::checkout(). `companyId` is nullable —
 * CLAUDE.md: "Orders must work without a company... the system will
 * also sell to the public" — a null companyId means a public/guest
 * order: card/prepay only, no credit check, no credit hold.
 *
 * `expectedTotalGrossMinor` is required (06 §9.3): the client's last
 * seen total from `checkout/preview`, compared against the server's own
 * re-resolution. A mismatch is 409 `price_changed` (PriceChangedException)
 * before any lock is taken.
 *
 * `deliveryCountryCode` has no default, for the same reason
 * OrderPricingPipeline's own parameter of the same name doesn't (see its
 * docblock): a silently-assumed country is a silently-wrong VAT rate.
 * CheckoutService has no delivery-address handling yet to derive this
 * from, so it is the caller's job to resolve it and pass it in.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public int $cartId,
        public ?int $companyId,
        public ?int $userId,
        public string $paymentMethod,
        public int $expectedTotalGrossMinor,
        public string $deliveryCountryCode,
        public ?int $placedByUserId = null,
        public string $channel = 'web',
        public ?string $customerReference = null,
        public int $shippingNetMinor = 0,
    ) {}
}
