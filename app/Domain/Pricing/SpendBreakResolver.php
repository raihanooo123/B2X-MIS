<?php

namespace App\Domain\Pricing;

use App\Models\OrderSpendBreak;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Doc 03 §7A.2 — selection only. Takes an already-computed
 * `qualifyingSubtotalMinor` as input; it does not know how that subtotal
 * was built (in particular, nothing about `applies_to_contract_lines`
 * or which lines were excluded) — that sequencing decision belongs to
 * whatever assembles the subtotal (OrderPricingPipeline), because only
 * the caller has the per-line `price_source` data needed to decide.
 *
 * Exactly one break applies, never stacked (§7A.2's own note, enforced
 * structurally here by `LIMIT 1`, and physically by
 * `order_spend_breaks_no_overlap`, 02 §6.7, for the active set at any
 * one instant).
 */
final class SpendBreakResolver
{
    public function resolve(
        int $qualifyingSubtotalMinor,
        ?int $tierId,
        ?int $companyId,
        string $currency,
        ?CarbonImmutable $at = null,
    ): ?OrderSpendBreak {
        return $this->ranked($tierId, $companyId, $currency, $at)
            ->where('min_subtotal_minor', '<=', $qualifyingSubtotalMinor)
            ->first();
    }

    /**
     * Every break this audience could reach, in exactly resolve()'s
     * order — the order pad's copy of the table (03 §7A.6: progress
     * "computed from the cached break table with no extra query").
     * resolve(S) is the first of these with `min_subtotal_minor <= S`,
     * so a client that walks the list in order selects what the server
     * would. Both methods share ranked(), so the ordering cannot drift.
     *
     * @return list<OrderSpendBreak>
     */
    public function candidates(?int $tierId, ?int $companyId, string $currency, ?CarbonImmutable $at = null): array
    {
        return array_values($this->ranked($tierId, $companyId, $currency, $at)->get()->all());
    }

    /**
     * @return Builder<OrderSpendBreak>
     */
    private function ranked(?int $tierId, ?int $companyId, string $currency, ?CarbonImmutable $at): Builder
    {
        $at ??= CarbonImmutable::now();

        return OrderSpendBreak::query()
            ->where('status', 'active')
            ->where('currency', $currency)
            ->whereRaw('validity @> ?::timestamptz', [$at->format('Y-m-d\TH:i:s.uP')])
            // Laravel turns where('col', null) into whereNull('col'), not
            // 'col = NULL' — different from PriceResolver's raw-SQL
            // approach, but safe: order_spend_breaks_coherence_chk (02
            // §6.7) guarantees a tier/company-scope row is never null on
            // its own audience column, so a null $tierId/$companyId still
            // matches zero rows in that branch either way.
            ->where(function ($query) use ($tierId, $companyId) {
                $query->where('scope', 'global')
                    ->orWhere(function ($q) use ($tierId) {
                        $q->where('scope', 'tier')->where('price_tier_id', $tierId);
                    })
                    ->orWhere(function ($q) use ($companyId) {
                        $q->where('scope', 'company')->where('company_id', $companyId);
                    });
            })
            ->orderByRaw("CASE scope WHEN 'company' THEN 1 WHEN 'tier' THEN 2 ELSE 3 END")
            ->orderByDesc('priority')
            ->orderByDesc('min_subtotal_minor')
            ->orderByDesc('id');
    }
}
