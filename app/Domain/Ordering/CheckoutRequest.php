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
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public int $cartId,
        public ?int $companyId,
        public ?int $userId,
        public string $paymentMethod,
        public int $expectedTotalGrossMinor,
        public ?int $placedByUserId = null,
        public string $channel = 'web',
        public ?string $customerReference = null,
        public int $shippingNetMinor = 0,
    ) {}
}
