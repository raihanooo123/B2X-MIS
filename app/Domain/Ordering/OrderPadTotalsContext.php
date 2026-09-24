<?php

namespace App\Domain\Ordering;

use App\Domain\Pricing\SpendBreakResolver;
use App\Models\Company;
use App\Models\OrderSpendBreak;
use App\Models\SystemConfiguration;
use Carbon\CarbonImmutable;

/**
 * The page-level inputs the order pad footer needs to recompute totals
 * client-side (05.1 §5.1, §5.3) — everything that is per order rather
 * than per SKU. Per-SKU inputs (effective break ladder, tax rate) come
 * from /pricing/bulk-resolve.
 *
 *   - `spend_breaks`: every break this buyer could reach, in
 *     SpendBreakResolver's selection order, so the client picks what
 *     OrderPricingPipeline would (03 §7A.6: "computed from the cached
 *     break table with no extra query"). `code`, never the internal id
 *     (06 §2) — the order carries the ranking.
 *   - `carriage_paid_threshold_net_minor`: 05.6 §6, company then global,
 *     then 05.6's stated default. Evaluated against the post-spend-break
 *     net subtotal. Zone overrides (`delivery_zones.carriage_paid_threshold_minor`)
 *     need a delivery address and a rating engine (05.6), neither of
 *     which exists at checkout yet, so they are not applied here either.
 *
 * Same tier and currency OrderPricingPipeline is given by
 * CheckoutPreviewService, so the two cannot select different breaks.
 * Customer-facing: nothing here is cost.
 */
final class OrderPadTotalsContext
{
    public const CARRIAGE_PAID_THRESHOLD_CONFIG_KEY = 'delivery.carriage_paid_threshold_net_minor';

    /** 05.6 §6's default: free mainland delivery above £500 net. */
    public const DEFAULT_CARRIAGE_PAID_THRESHOLD_NET_MINOR = 50000;

    public function __construct(
        private readonly SpendBreakResolver $spendBreakResolver = new SpendBreakResolver,
    ) {}

    /**
     * @return array{
     *     spend_breaks: list<array{code: string, name: string, min_subtotal_minor: int, discount_type: string, discount_rate_bp: int|null, discount_amount_minor: int|null, max_discount_minor: int|null, applies_to_contract_lines: bool}>,
     *     carriage_paid_threshold_net_minor: int,
     * }
     */
    public function for(?int $companyId, ?CarbonImmutable $at = null): array
    {
        $breaks = $this->spendBreakResolver->candidates($this->tierId($companyId), $companyId, 'GBP', $at);

        return [
            'spend_breaks' => array_map(fn (OrderSpendBreak $b): array => [
                'code' => $b->code,
                'name' => $b->name,
                'min_subtotal_minor' => (int) $b->min_subtotal_minor,
                'discount_type' => $b->discount_type,
                'discount_rate_bp' => $b->discount_rate_bp,
                'discount_amount_minor' => $b->discount_amount_minor,
                'max_discount_minor' => $b->max_discount_minor,
                'applies_to_contract_lines' => $b->applies_to_contract_lines,
            ], $breaks),
            'carriage_paid_threshold_net_minor' => $this->carriagePaidThresholdNetMinor($companyId),
        ];
    }

    /**
     * 02 §2.7 most-specific-wins, company then global (as
     * CheckoutPreviewService resolves the minimum order value).
     */
    private function carriagePaidThresholdNetMinor(?int $companyId): int
    {
        $rows = SystemConfiguration::query()
            ->where('config_key', self::CARRIAGE_PAID_THRESHOLD_CONFIG_KEY)
            ->where(function ($q) use ($companyId) {
                $q->where('scope', 'global');
                if ($companyId !== null) {
                    $q->orWhere(fn ($q) => $q->where('scope', 'company')->where('company_id', $companyId));
                }
            })
            ->get(['scope', 'value_int'])
            ->keyBy('scope');

        $row = $rows->get('company') ?? $rows->get('global');

        return $row->value_int ?? self::DEFAULT_CARRIAGE_PAID_THRESHOLD_NET_MINOR;
    }

    private function tierId(?int $companyId): ?int
    {
        if ($companyId === null) {
            return null;
        }

        $tierId = Company::query()->whereKey($companyId)->value('price_tier_id');

        return $tierId === null ? null : (int) $tierId;
    }
}
