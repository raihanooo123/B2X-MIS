<?php

namespace App\Domain\Warehouse;

use App\Models\StocktakeLine;

/**
 * One counted line as it would post (02 §24.1–24.2): what was counted,
 * the level now, the level as it stood when counted (reconstructed from
 * the movements since), the variance between count and that, and — for
 * serial-tracked lines — the serials missing and found, by number.
 * `blockers` are reasons the line cannot post as it stands.
 */
final class StocktakeLineReview
{
    /**
     * @param  list<string>  $missingSerials
     * @param  list<string>  $foundSerials
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly StocktakeLine $line,
        public readonly int $onHandNow,
        public readonly int $expectedAtCount,
        public readonly array $missingSerials = [],
        public readonly array $foundSerials = [],
        public readonly array $blockers = [],
    ) {}

    public function variance(): int
    {
        return $this->line->counted_base_qty - $this->expectedAtCount;
    }
}
