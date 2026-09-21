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
 */
final class ConsumerCheckout implements CheckoutStrategy
{
    private const ALLOWED_PAYMENT_METHODS = ['card', 'prepay'];

    public function validate(CheckoutRequest $request): void
    {
        if (! in_array($request->paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException(
                "Public/guest checkout is card or prepay only, got payment_method '{$request->paymentMethod}' — on_account requires a company."
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
