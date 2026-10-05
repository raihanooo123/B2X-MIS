<?php

namespace App\Jobs;

use App\Domain\Storefront\ProductPriceProjector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 02 §29.4 — recompute storefront price sort keys after a catalogue,
 * price or tax write commits. Idempotent: running it twice changes
 * nothing. `productIds` null means every product (a base list switch);
 * `taxClassIds` set means the products in those classes (a GB VAT change).
 *
 * A sort key must never fail the write that triggered it (the queue is
 * synchronous in tests and may be in small deployments): a failure is
 * logged, and the nightly rebuild puts the rows right.
 */
class RefreshPriceProjection implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>|null  $productIds
     * @param  list<int>  $taxClassIds
     */
    public function __construct(
        public readonly ?array $productIds = null,
        public readonly array $taxClassIds = [],
    ) {
        $this->afterCommit();
    }

    public function handle(ProductPriceProjector $projector): void
    {
        try {
            if ($this->taxClassIds !== []) {
                $projector->refreshTaxClasses($this->taxClassIds);
            } elseif ($this->productIds === null) {
                $projector->refreshAll();
            } else {
                $projector->refresh($this->productIds);
            }
        } catch (Throwable $e) {
            Log::warning('Storefront price projection not refreshed; the nightly rebuild will (02 §29.4).', [
                'product_ids' => $this->productIds,
                'tax_class_ids' => $this->taxClassIds,
                'exception' => $e,
            ]);
        }
    }
}
