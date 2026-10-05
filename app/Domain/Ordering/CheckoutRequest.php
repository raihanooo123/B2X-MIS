<?php

namespace App\Domain\Ordering;

use App\Domain\Accounts\AcceptedTerms;
use App\Domain\Billing\CardIntent;

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
 * It is the caller's job to resolve it — from `deliveryAddress` when
 * one is given — and pass it in.
 *
 * `deliveryAddress`, when given, is snapshotted onto `order_addresses`
 * (02 §8.4) in the order's own transaction.
 *
 * `saleTerms`, when given, is the terms of sale version a public buyer
 * accepted (05.15 §6.1 step 4). It must be the version in force, and is
 * recorded as a `terms_acceptances` row in the order's transaction
 * (02 §25.1, §26.2). Requiring it for web checkout is the caller's job:
 * a phone or rep order has no browser acceptance to record.
 *
 * `guestEmail` is a guest's contact email (05.15 §6.1, 02 §26.1): set
 * exactly when there is neither a company nor a user. Stored trimmed and
 * lower-cased on `orders.guest_email`.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public int $cartId,
        public ?int $companyId,
        public ?int $userId,
        /** An `orders.payment_method` value (PaymentMethod, 02 §18) — stored on the order as given. */
        public string $paymentMethod,
        public int $expectedTotalGrossMinor,
        public string $deliveryCountryCode,
        public ?int $placedByUserId = null,
        public string $channel = 'web',
        public ?string $customerReference = null,
        public int $shippingNetMinor = 0,
        public ?DeliveryAddress $deliveryAddress = null,
        /**
         * A card payment already *authorised* for this order (07 §6.4, 04
         * §4.4). Recorded inside the order's transaction; captured by the
         * caller after commit.
         */
        public ?CardIntent $cardAuthorisation = null,
        public ?AcceptedTerms $saleTerms = null,
        public ?string $guestEmail = null,
        public string $fulfilmentType = 'delivery',
        public ?int $collectionSlotId = null,
        public bool $applyAccountCredit = false,
    ) {}
}
