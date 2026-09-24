<?php

namespace App\Domain\Billing\Events;

/**
 * A card payment was captured (Stripe). Ordering listens and marks the
 * order paid (CLAUDE.md: contexts talk via domain events). Dispatched
 * after commit; carries ids only.
 */
final readonly class PaymentCaptured
{
    public function __construct(
        public int $paymentId,
        public ?int $orderId,
    ) {}
}
