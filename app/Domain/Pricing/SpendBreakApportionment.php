<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §7A.3 output. `perLine` covers only the lines passed in as
 * qualifying — a non-qualifying line (excluded contract line, or simply
 * not passed to SpendBreakApportioner::apportion()) is not a key here at
 * all; callers treat "absent" as zero, per §7A.3: "Non-qualifying lines
 * receive zero."
 */
final readonly class SpendBreakApportionment
{
    /**
     * @param  array<int|string, int>  $perLine  line_spend_discount_minor keyed identically to the input
     */
    public function __construct(
        public int $discountMinor,
        public array $perLine,
    ) {}
}
