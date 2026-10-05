<?php

namespace App\Domain\Collection;

use RuntimeException;

/** 05.6 §7A.3, §7A.9: answered 422 `pay_at_collection_not_eligible`, with the reason. */
final class PayAtCollectionNotEligible extends RuntimeException
{
    public function __construct(public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }
}
