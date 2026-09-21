<?php

namespace App\Domain\Pricing\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Doc 03 §10: the rate is resolved from `skus.tax_class_id` and the
 * delivery country, never guessed. Reaching this means the SKU's tax
 * class has no `tax_rates` row valid at the requested time for that
 * country — a data-setup gap (every tax class needs at least a national
 * rate, per the doc's seed: standard/zero/reduced), never silently
 * treated as 0%.
 */
final class NoTaxRateException extends RuntimeException
{
    public function __construct(
        public readonly int $taxClassId,
        public readonly string $countryCode,
        public readonly CarbonImmutable $at,
    ) {
        parent::__construct(
            "No tax_rates row for tax_class {$taxClassId} in country {$countryCode} valid at {$at->toIso8601String()}."
        );
    }
}
