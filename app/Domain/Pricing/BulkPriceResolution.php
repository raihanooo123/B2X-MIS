<?php

namespace App\Domain\Pricing;

/**
 * Doc 03 §4.6: the engine never returns null and never substitutes a
 * default for one SKU — but a 100-row order-pad page cannot let one
 * unpriceable SKU fail the whole page either. `$resolved` and
 * `$failures` partition the requested SKU ids between the two: every
 * requested id appears in exactly one of them.
 */
final readonly class BulkPriceResolution
{
    /**
     * @param  array<int, ResolvedPrice>  $resolved  keyed by sku_id
     * @param  array<int, class-string<\Throwable>>  $failures  keyed by sku_id — the exception class that would have been thrown for that SKU alone
     * @param  array<int, list<PriceBreak>>  $breaks  keyed by sku_id, present only for resolved SKUs — the effective ladder across every candidate list (06 §9.1, see PriceBreak)
     */
    public function __construct(
        public array $resolved,
        public array $failures,
        public array $breaks = [],
    ) {}
}
