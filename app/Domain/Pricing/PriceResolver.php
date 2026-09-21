<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\NoBasePriceListException;
use App\Domain\Pricing\Exceptions\NotPurchasableException;
use App\Domain\Pricing\Exceptions\PriceUnavailableForCurrencyException;
use App\Models\Company;
use App\Models\Sku;
use App\Models\SkuCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 03 §4 — the resolution algorithm. Answers "given a SKU, a customer,
 * a quantity and a moment in time, what is the price?" Never returns
 * null, never silently substitutes a default (§4.6) — every path either
 * returns a complete ResolvedPrice or throws a named exception.
 *
 * Out of scope for this class, deliberately (separate domain services,
 * not yet built):
 *   - §6/§7A line and order construction (rounding, coupons, spend
 *     breaks) — this class only resolves a unit price, it does not build
 *     an order line.
 *   - §8 bulk resolution — the order-pad path batches many SKUs against
 *     cached candidate lists; this class resolves one SKU at a time.
 *   - §9 caching — resolve() hits the database directly. A caching
 *     decorator belongs in front of this class, not inside it.
 *   - §10 tax rate resolution — see ResolvedPrice's docblock; taxRateBp
 *     is always 0 here pending a signature or design decision this
 *     class cannot make for itself.
 *
 * Promotion resolution (rank 3, §4.2) depends on PromotionEligibilityResolver,
 * which defaults to NullPromotionEligibilityResolver until
 * `promotions`/`promotion_rules` (02 §14.8, DRAFT) are signed off.
 */
final class PriceResolver
{
    public function __construct(
        private readonly PromotionEligibilityResolver $promotionEligibility = new NullPromotionEligibilityResolver,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws NotPurchasableException
     * @throws NoBasePriceListException
     * @throws PriceUnavailableForCurrencyException
     */
    public function resolve(
        int $skuId,
        ?int $companyId,
        int $baseQty,
        ?CarbonImmutable $at = null,
        string $currency = 'GBP',
    ): ResolvedPrice {
        if ($baseQty <= 0) {
            throw new InvalidArgumentException("base_qty must be a positive integer, got {$baseQty}.");
        }

        $sku = Sku::query()->findOrFail($skuId);
        if ($sku->status !== 'active') {
            throw new NotPurchasableException($skuId, $sku->status);
        }

        $at ??= CarbonImmutable::now();

        $tierId = $companyId !== null
            ? Company::query()->whereKey($companyId)->value('price_tier_id')
            : null;
        $tierId = $tierId === null ? null : (int) $tierId;

        $eligiblePromotionListIds = $this->promotionEligibility->eligiblePriceListIds($companyId);

        $winner = $this->findCandidate($skuId, $tierId, $companyId, $baseQty, $at, $currency, $eligiblePromotionListIds);

        if ($winner === null) {
            $this->failResolution($skuId, $at, $currency);
        }

        [$skuCostId, $unitCostE4] = $this->currentCost($skuId, $at);
        $resolved = $this->buildResolvedPrice($winner, $skuId, $baseQty, $skuCostId, $unitCostE4, promotionCapped: false);

        if ($resolved->priceSource !== PriceSource::Promotion) {
            return $resolved;
        }

        // §4.5 promotion cap: a promotion may never cost the customer
        // more than not having it. Re-resolve ignoring promotions; if
        // that would have been cheaper, use it instead and flag the cap.
        $withoutPromotion = $this->findCandidate(
            $skuId, $tierId, $companyId, $baseQty, $at, $currency,
            eligiblePromotionListIds: [], excludePromotions: true,
        );

        if ($withoutPromotion !== null && $withoutPromotion->unit_price_e4 < $winner->unit_price_e4) {
            return $this->buildResolvedPrice($withoutPromotion, $skuId, $baseQty, $skuCostId, $unitCostE4, promotionCapped: true);
        }

        return $resolved;
    }

    /**
     * Doc 03 §4.4. Single round trip: ranks every candidate price list
     * row for this SKU and returns the winner, or null if nothing
     * matches. `pl.has_contract = true` (not `= 1`, as the doc's own
     * literal SQL has it) — Postgres does not implicitly compare a real
     * boolean column against an integer literal.
     *
     * @param  list<int>  $eligiblePromotionListIds
     * @return \stdClass|null Row with price_list_id, price_list_item_id, min_base_qty, unit_price_e4, scope, has_contract
     */
    private function findCandidate(
        int $skuId,
        ?int $tierId,
        ?int $companyId,
        int $baseQty,
        CarbonImmutable $at,
        string $currency,
        array $eligiblePromotionListIds,
        bool $excludePromotions = false,
    ): ?\stdClass {
        $promotionListLiteral = '{'.implode(',', $eligiblePromotionListIds).'}';

        $sql = <<<'SQL'
            SELECT pli.price_list_id,
                   pli.id           AS price_list_item_id,
                   pli.min_base_qty,
                   pli.unit_price_e4,
                   pl.scope,
                   pl.has_contract
            FROM   price_list_items pli
            JOIN   price_lists pl ON pl.id = pli.price_list_id
            WHERE  pli.sku_id = ?
              AND  pli.min_base_qty <= ?
              AND  pl.status = 'active'
              AND  pl.currency = ?
              AND  pl.validity @> ?::timestamptz
              AND  (
                     pl.scope = 'base'
                  OR (pl.scope = 'tier'      AND pl.price_tier_id = ?)
                  OR (pl.scope = 'company'   AND pl.company_id    = ?)
                  OR (pl.scope = 'promotion' AND pl.id = ANY(?))
                   )
            SQL;

        if ($excludePromotions) {
            $sql .= "\n  AND  pl.scope != 'promotion'";
        }

        $sql .= <<<'SQL'

            ORDER BY CASE
                       WHEN pl.scope = 'company'   AND pl.has_contract = true THEN 1
                       WHEN pl.scope = 'company'                              THEN 2
                       WHEN pl.scope = 'promotion'                            THEN 3
                       WHEN pl.scope = 'tier'                                 THEN 4
                       ELSE 5
                     END,
                     pl.priority DESC,
                     pl.id DESC,
                     pli.min_base_qty DESC
            LIMIT 1
            SQL;

        $rows = DB::select($sql, [
            $skuId,
            $baseQty,
            $currency,
            $this->timestampForQuery($at),
            $tierId,
            $companyId,
            $promotionListLiteral,
        ]);

        return $rows[0] ?? null;
    }

    /**
     * Doc 03 §4.5: "read from the same result set" describes intent, not
     * a literal single query — the ranking query above filters
     * `min_base_qty <= base_qty`, so it structurally cannot also return a
     * higher, not-yet-reached break row. This is a second, tiny,
     * index-served lookup against the one winning price_list_id — not
     * the bulk §8 path the "single round trip" language protects.
     *
     * @return array{0: ?int, 1: ?int} [nextBreakQty, nextBreakUnitPriceE4]
     */
    private function nextBreak(int $priceListId, int $skuId, int $appliedBreakQty): array
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT min_base_qty, unit_price_e4
                FROM   price_list_items
                WHERE  price_list_id = ? AND sku_id = ? AND min_base_qty > ?
                ORDER  BY min_base_qty ASC
                LIMIT  1
                SQL,
            [$priceListId, $skuId, $appliedBreakQty],
        );

        return $row === null ? [null, null] : [(int) $row->min_base_qty, (int) $row->unit_price_e4];
    }

    /**
     * `Carbon::toIso8601String()` truncates to whole seconds. Postgres's
     * `now()` default on `validity` carries microsecond precision, so a
     * row created and resolved against within the same wall-clock second
     * can have its lower bound *after* a truncated `$at` — `validity @>
     * $at` then wrongly fails and every candidate is missed. Formatting
     * with microseconds fixes it.
     */
    private function timestampForQuery(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @return array{0: ?int, 1: ?int} [skuCostId, landedCostE4]
     */
    private function currentCost(int $skuId, CarbonImmutable $at): array
    {
        $cost = SkuCost::query()
            ->where('sku_id', $skuId)
            ->where('valid_from', '<=', $at)
            ->orderByDesc('valid_from')
            ->first(['id', 'landed_cost_e4']);

        return $cost === null ? [null, null] : [$cost->id, $cost->landed_cost_e4];
    }

    private function buildResolvedPrice(\stdClass $winner, int $skuId, int $baseQty, ?int $skuCostId, ?int $unitCostE4, bool $promotionCapped): ResolvedPrice
    {
        [$nextBreakQty, $nextBreakUnitPriceE4] = $this->nextBreak(
            (int) $winner->price_list_id, $skuId, (int) $winner->min_base_qty,
        );

        return new ResolvedPrice(
            skuId: $skuId,
            baseQty: $baseQty,
            unitPriceE4: (int) $winner->unit_price_e4,
            priceSource: $this->priceSourceFor((string) $winner->scope, (bool) $winner->has_contract),
            priceListId: (int) $winner->price_list_id,
            priceListItemId: (int) $winner->price_list_item_id,
            appliedBreakQty: (int) $winner->min_base_qty,
            taxRateBp: 0,
            nextBreakQty: $nextBreakQty,
            nextBreakUnitPriceE4: $nextBreakUnitPriceE4,
            unitCostE4: $unitCostE4,
            skuCostId: $skuCostId,
            promotionCapped: $promotionCapped,
        );
    }

    private function priceSourceFor(string $scope, bool $hasContract): PriceSource
    {
        return match (true) {
            $scope === 'company' && $hasContract => PriceSource::Contract,
            $scope === 'company' => PriceSource::Customer,
            $scope === 'promotion' => PriceSource::Promotion,
            $scope === 'tier' => PriceSource::Tier,
            default => PriceSource::Base,
        };
    }

    /**
     * Doc 03 §4.6. Distinguishes "no base list at all" (hard error) from
     * "a base list exists, just not in this currency" by re-checking
     * without the currency filter — only ever run on the rare
     * not-found path, never the hot path.
     */
    private function failResolution(int $skuId, CarbonImmutable $at, string $currency): never
    {
        $hasAnyBaseList = DB::selectOne(
            <<<'SQL'
                SELECT 1
                FROM   price_list_items pli
                JOIN   price_lists pl ON pl.id = pli.price_list_id
                WHERE  pli.sku_id = ?
                  AND  pl.scope = 'base'
                  AND  pl.status = 'active'
                  AND  pl.validity @> ?::timestamptz
                LIMIT  1
                SQL,
            [$skuId, $this->timestampForQuery($at)],
        );

        if ($hasAnyBaseList !== null) {
            throw new PriceUnavailableForCurrencyException($skuId, $currency);
        }

        throw new NoBasePriceListException($skuId, $currency);
    }
}
