<?php

namespace App\Domain\Ordering;

use App\Domain\Inventory\AllocationService;
use App\Models\Order;
use InvalidArgumentException;

/**
 * Public/guest checkout — CLAUDE.md: "the system will also sell to the
 * public... card/prepay only, no credit check, no credit hold." No
 * company, so no price tier and nothing to lock or gate on the
 * `companies` row — stock is the only thing this strategy reserves.
 *
 * Public delivery is GB only (05.15 §6.1 rule G): the HTTP layer answers
 * 422 `country_not_served` first; this is the domain's backstop.
 *
 * A guest (no user either, 05.15 §6.1) is identified by `guestEmail` and
 * pays by card only (step 3): no BACS, no account, no credit.
 */
final class ConsumerCheckout implements CheckoutStrategy
{
    /** 05.15 §6.1 rule G. Jersey, Guernsey and the Isle of Man are outside it. */
    public const SERVED_COUNTRY = 'GB';

    private const ALLOWED_PAYMENT_METHODS = ['card', 'bacs', 'prepay'];

    public static function servesCountry(string $countryCode): bool
    {
        return strtoupper(trim($countryCode)) === self::SERVED_COUNTRY;
    }

    public function validate(CheckoutRequest $request): void
    {
        if (! self::servesCountry($request->deliveryCountryCode)) {
            throw new InvalidArgumentException("Public/guest orders are delivered to GB only, got '{$request->deliveryCountryCode}' (05.15 §6.1 rule G).");
        }

        if (($request->userId === null) !== ($request->guestEmail !== null)) {
            throw new InvalidArgumentException('A public order names its customer by user or, for a guest, by guest email — exactly one of them (02 §26.1).');
        }

        if ($request->guestEmail !== null && $request->paymentMethod !== 'card') {
            throw new InvalidArgumentException("Guest checkout is card only, got payment_method '{$request->paymentMethod}' (05.15 §6.1 step 3).");
        }

        if (! in_array($request->paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException(
                "Public/guest checkout is card, BACS or prepay only, got payment_method '{$request->paymentMethod}' — on_account requires a company."
            );
        }
    }

    public function tierId(CheckoutRequest $request): ?int
    {
        return null;
    }

    public function paymentStatus(CheckoutRequest $request): string
    {
        return 'unpaid';
    }

    public function reserve(
        AllocationService $allocationService,
        CheckoutRequest $request,
        Order $order,
        int $totalGrossMinor,
        array $allocationLines,
    ): void {
        if ($allocationLines !== []) {
            $allocationService->allocateWithinTransaction(null, 0, $allocationLines);
        }
    }
}
