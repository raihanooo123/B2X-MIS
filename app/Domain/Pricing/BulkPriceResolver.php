<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\NoBasePriceListException;
use App\Domain\Pricing\Exceptions\NotPurchasableException;
use App\Domain\Pricing\Exceptions\PriceUnavailableForCurrencyException;
use App\Models\Company;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 03 §8 — the order-pad path. A genuinely different code path from
 * PriceResolver, not a loop calling it 100 times: exactly two queries
 * regardless of row count (Q-A candidate lists, Q-B the full break table
 * for those lists × these SKUs), with ranking and break selection done
 * in PHP over the in-memory result — "the data is tiny and the logic is
 * testable without a database."
 *
 * Q-C (stock levels) is deliberately not this class's job — it is an
 * Inventory-domain query (`stock_levels` PRIMARY), and CLAUDE.md's
 * convention is contexts talk via domain events, not cross-context
 * queries. Whatever assembles the order-pad page calls this and a
 * stock-lookup service separately, per the doc's own three-query split.
 *
 * The ranking rule implemented here (rank per §4.2's CASE, then
 * priority DESC, then price_list id DESC, then min_base_qty DESC) MUST
 * stay identical to PriceResolver::findCandidate()'s SQL ORDER BY —
 * PriceResolverBulkConsistencyTest cross-checks the two against shared
 * fixtures specifically to catch drift, since there is no shared code
 * between a SQL ORDER BY and this PHP sort to keep them in sync
 * automatically.
 *
 * unitCostE4 is deliberately always null here (unlike PriceResolver) —
 * §8 enumerates exactly three queries as the budget-critical contract;
 * a per-SKU cost lookup would be a fourth. Cost is an admin/rep-context
 * concern (CLAUDE.md invariant 9) the order-pad's customer context
 * doesn't need.
 */
final class BulkPriceResolver
{
    public function __construct(
        private readonly PromotionEligibilityResolver $promotionEligibility = new NullPromotionEligibilityResolver,
    ) {}

    /**
     * @param  list<int>  $skuIds
     */
    public function resolveMany(array $skuIds, ?int $companyId, int $baseQty, ?CarbonImmutable $at = null, string $currency = 'GBP'): BulkPriceResolution
    {
        if ($skuIds === []) {
            return new BulkPriceResolution([], []);
        }

        if ($baseQty <= 0) {
            throw new InvalidArgumentException("base_qty must be a positive integer, got {$baseQty}.");
        }

        $at ??= CarbonImmutable::now();

        $tierId = $companyId !== null ? Company::query()->whereKey($companyId)->value('price_tier_id') : null;
        $tierId = $tierId === null ? null : (int) $tierId;

        $eligiblePromotionListIds = $this->promotionEligibility->eligiblePriceListIds($companyId);

        // Q-A
        $lists = $this->candidatePriceLists($tierId, $companyId, $at, $currency, $eligiblePromotionListIds);

        // Q-B
        $itemsBySku = $this->breakRows(array_keys($lists), $skuIds);

        $activeSkuIds = Sku::query()->whereIn('id', $skuIds)->where('status', 'active')->pluck('id')->all();
        $activeSkuIds = array_flip($activeSkuIds);

        $resolved = [];
        $failures = [];

        foreach ($skuIds as $skuId) {
            if (! isset($activeSkuIds[$skuId])) {
                $failures[$skuId] = NotPurchasableException::class;

                continue;
            }

            $rows = $itemsBySku[$skuId] ?? [];

            try {
                $resolved[$skuId] = $this->resolveOne($skuId, $rows, $lists, $baseQty, $at, $currency);
            } catch (NoBasePriceListException|PriceUnavailableForCurrencyException $e) {
                $failures[$skuId] = $e::class;
            }
        }

        return new BulkPriceResolution($resolved, $failures);
    }

    /**
     * Doc 03 §8 Q-A: the candidate price_lists visible to this company,
     * one row set, cacheable per company (03 §9) — not attempted here,
     * caching is a decorator's job.
     *
     * @param  list<int>  $eligiblePromotionListIds
     * @return array<int, \stdClass> price_list rows keyed by id
     */
    private function candidatePriceLists(?int $tierId, ?int $companyId, CarbonImmutable $at, string $currency, array $eligiblePromotionListIds): array
    {
        $promotionListLiteral = '{'.implode(',', $eligiblePromotionListIds).'}';

        $rows = DB::select(
            <<<'SQL'
                SELECT id, scope, has_contract, priority
                FROM   price_lists
                WHERE  status = 'active'
                  AND  currency = ?
                  AND  validity @> ?::timestamptz
                  AND  (
                         scope = 'base'
                      OR (scope = 'tier'      AND price_tier_id = ?)
                      OR (scope = 'company'   AND company_id    = ?)
                      OR (scope = 'promotion' AND id = ANY(?))
                       )
                SQL,
            [$currency, $this->timestampForQuery($at), $tierId, $companyId, $promotionListLiteral],
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        return $byId;
    }

    /**
     * Doc 03 §8 Q-B: the full, unfiltered break table for every
     * candidate list x requested SKU. No `min_base_qty` predicate — the
     * whole ladder comes back so the UI can recompute on a quantity
     * change with no round trip, and so this class can find the "next
     * break" from data it already has.
     *
     * @param  list<int>  $priceListIds
     * @param  list<int>  $skuIds
     * @return array<int, list<\stdClass>> break rows keyed by sku_id
     */
    private function breakRows(array $priceListIds, array $skuIds): array
    {
        if ($priceListIds === []) {
            return [];
        }

        $listLiteral = '{'.implode(',', $priceListIds).'}';
        $skuLiteral = '{'.implode(',', $skuIds).'}';

        $rows = DB::select(
            'select price_list_id, sku_id, min_base_qty, unit_price_e4
             from price_list_items
             where price_list_id = any(?) and sku_id = any(?)',
            [$listLiteral, $skuLiteral],
        );

        $bySku = [];
        foreach ($rows as $row) {
            $bySku[(int) $row->sku_id][] = $row;
        }

        return $bySku;
    }

    /**
     * @param  list<\stdClass>  $rows  this SKU's break rows across every candidate list
     * @param  array<int, \stdClass>  $lists  candidate price_lists keyed by id
     */
    private function resolveOne(int $skuId, array $rows, array $lists, int $baseQty, CarbonImmutable $at, string $currency): ResolvedPrice
    {
        $winner = $this->rank($rows, $lists, $baseQty);

        if ($winner === null) {
            $this->failResolution($skuId, $at, $currency);
        }

        $priceSource = $this->classify($winner, $lists);

        $resolved = $this->toResolvedPrice($skuId, $baseQty, $winner, $rows, $priceSource);

        if ($priceSource !== PriceSource::Promotion) {
            return $resolved;
        }

        // §4.5 promotion cap — free here, no extra query: the same
        // in-memory row set already has every non-promotion candidate.
        $nonPromotionListIds = array_keys(array_filter($lists, fn ($l) => $l->scope !== 'promotion'));
        $rowsWithoutPromotion = array_values(array_filter($rows, fn ($r) => in_array((int) $r->price_list_id, $nonPromotionListIds, true)));
        $withoutPromotion = $this->rank($rowsWithoutPromotion, $lists, $baseQty);

        if ($withoutPromotion !== null && (int) $withoutPromotion->unit_price_e4 < (int) $winner->unit_price_e4) {
            $fallbackSource = $this->classify($withoutPromotion, $lists);

            return $this->toResolvedPrice($skuId, $baseQty, $withoutPromotion, $rowsWithoutPromotion, $fallbackSource, promotionCapped: true);
        }

        return $resolved;
    }

    /**
     * Mirrors PriceResolver::findCandidate()'s SQL ORDER BY exactly:
     * rank (§4.2), then price_list priority DESC, then price_list id
     * DESC, then min_base_qty DESC.
     *
     * @param  list<\stdClass>  $rows
     * @param  array<int, \stdClass>  $lists
     */
    private function rank(array $rows, array $lists, int $baseQty): ?\stdClass
    {
        $qualifying = array_values(array_filter($rows, fn ($r) => (int) $r->min_base_qty <= $baseQty && isset($lists[(int) $r->price_list_id])));

        if ($qualifying === []) {
            return null;
        }

        usort($qualifying, function (\stdClass $a, \stdClass $b) use ($lists) {
            $listA = $lists[(int) $a->price_list_id];
            $listB = $lists[(int) $b->price_list_id];

            return [$this->rankOf($listA), -(int) $listA->priority, -(int) $a->price_list_id, -(int) $a->min_base_qty]
                <=> [$this->rankOf($listB), -(int) $listB->priority, -(int) $b->price_list_id, -(int) $b->min_base_qty];
        });

        return $qualifying[0];
    }

    private function rankOf(\stdClass $list): int
    {
        return match (true) {
            $list->scope === 'company' && (bool) $list->has_contract => 1,
            $list->scope === 'company' => 2,
            $list->scope === 'promotion' => 3,
            $list->scope === 'tier' => 4,
            default => 5,
        };
    }

    /**
     * @param  array<int, \stdClass>  $lists
     */
    private function classify(\stdClass $winner, array $lists): PriceSource
    {
        $list = $lists[(int) $winner->price_list_id];

        return match (true) {
            $list->scope === 'company' && (bool) $list->has_contract => PriceSource::Contract,
            $list->scope === 'company' => PriceSource::Customer,
            $list->scope === 'promotion' => PriceSource::Promotion,
            $list->scope === 'tier' => PriceSource::Tier,
            default => PriceSource::Base,
        };
    }

    /**
     * @param  list<\stdClass>  $rowsConsidered  the row set the winner was chosen from, reused to find the next break with no extra query
     */
    private function toResolvedPrice(int $skuId, int $baseQty, \stdClass $winner, array $rowsConsidered, PriceSource $priceSource, bool $promotionCapped = false): ResolvedPrice
    {
        $winningListId = (int) $winner->price_list_id;
        $appliedBreakQty = (int) $winner->min_base_qty;

        $nextBreakQty = null;
        $nextBreakUnitPriceE4 = null;
        foreach ($rowsConsidered as $row) {
            if ((int) $row->price_list_id !== $winningListId || (int) $row->min_base_qty <= $appliedBreakQty) {
                continue;
            }
            if ($nextBreakQty === null || (int) $row->min_base_qty < $nextBreakQty) {
                $nextBreakQty = (int) $row->min_base_qty;
                $nextBreakUnitPriceE4 = (int) $row->unit_price_e4;
            }
        }

        return new ResolvedPrice(
            skuId: $skuId,
            baseQty: $baseQty,
            unitPriceE4: (int) $winner->unit_price_e4,
            priceSource: $priceSource,
            priceListId: $winningListId,
            priceListItemId: null,
            appliedBreakQty: $appliedBreakQty,
            taxRateBp: 0,
            nextBreakQty: $nextBreakQty,
            nextBreakUnitPriceE4: $nextBreakUnitPriceE4,
            unitCostE4: null,
            promotionCapped: $promotionCapped,
        );
    }

    /**
     * Doc 03 §4.6, adapted for the bulk path. Q-A already filtered
     * candidate lists to the requested currency, so it cannot tell "no
     * base list at all" apart from "a base list exists, just in another
     * currency" — this SKU has already failed to resolve, which is rare
     * and exceptional, so a one-off diagnostic query here (mirroring
     * PriceResolver::failResolution() exactly) is acceptable; it never
     * runs on the Q-A/Q-B hot path §8's budget protects.
     *
     * @throws NoBasePriceListException
     * @throws PriceUnavailableForCurrencyException
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

    private function timestampForQuery(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s.uP');
    }
}
