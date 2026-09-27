<?php

namespace App\Domain\Accounts;

use App\Domain\Billing\PaymentTerms;
use InvalidArgumentException;

/**
 * What a reviewer grants at approval (05.2 §5.4, §5.6 step 2): tier,
 * payment terms and credit limit, in whole pence (invariant 1).
 */
final readonly class ApprovalTerms
{
    public function __construct(
        public int $priceTierId,
        public PaymentTerms $paymentTerms,
        public int $creditLimitMinor,
    ) {
        if ($creditLimitMinor < 0) {
            throw new InvalidArgumentException('A credit limit cannot be negative.');
        }
    }
}
