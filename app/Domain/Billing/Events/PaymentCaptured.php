<?php

namespace App\Domain\Billing\Events;

/**
 * A card payment was captured (Stripe), after commit; carries ids only.
 * For side effects (notifications, reporting). The order is already
 * `paid` by the time this fires — that is written in the capture
 * transaction itself (CardPayments::markCaptured), not by a listener.
 */
final readonly class PaymentCaptured
{
    public function __construct(
        public int $paymentId,
        public ?int $orderId,
    ) {}
}
