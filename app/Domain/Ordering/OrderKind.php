<?php

namespace App\Domain\Ordering;

/**
 * Mirrors `orders_kind_chk` (05.4 §14.3). A replacement is a zero-value
 * order sending goods to replace faulty ones: no payment
 * (`payment_status = 'not_required'`), no invoice, no consumer
 * cancellation, released to the warehouse at once.
 */
enum OrderKind: string
{
    case Sale = 'sale';
    case Replacement = 'replacement';

    /** 05.4 §14.2 R8: the only payment status a replacement may have. */
    public const NOT_REQUIRED = 'not_required';
}
