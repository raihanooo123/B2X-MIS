/**
 * Order pad local recompute (05.1 §5.1): unit price at the reached
 * break, line totals, spend break, VAT and totals, entirely client-side
 * over data the page already holds — the effective break ladder and tax
 * rate from /pricing/bulk-resolve, and the spend-break table and
 * carriage-paid threshold from the `totals_context` page prop. No
 * network, no React, no floats.
 *
 * It mirrors OrderPricingPipeline (03 §7A.4) step for step, because the
 * client's totals must equal checkout preview's exactly (05.1 §11; any
 * mismatch is a defect, not a tolerance — LocalRecomputeParityTest
 * holds it to that):
 *
 *   Pass 1  unit price at the reached rung; item net = round_half_up(e4 × qty / 100)
 *   Pass 2  spend break selection (incl. the applies_to_contract_lines
 *           wrinkle) and SpendBreakApportioner's apportionment
 *   Pass 3  per-line tax on the post-discount net; sums
 *
 * Shipping is 0, as checkout charges today (delivery rating, 05.6, is
 * not built); the footer shows progress toward free delivery only.
 *
 * The client is a calculator, never an authority: the server
 * re-resolves on submit and its figures win (05.1 §5.1).
 *
 * Imports carry `.ts` extensions so Node can run this module directly —
 * the parity test executes this exact file, not a copy.
 */
import type { BulkResolveEntry, PriceBreak, PriceSource } from '../api/orderPad.ts';
import { ceilDivInts, lineNetMinor, lineTaxMinor, mulDivHalfUp, multiplyInts, subtractInts, sumInts } from '../money.ts';

/** Per-SKU inputs: the effective ladder (ascending by min_base_qty) and VAT rate. */
export interface LinePricing {
    breaks: readonly PriceBreak[];
    taxRateBp: number;
}

/** One `totals_context.spend_breaks` entry, already in selection order. */
export interface SpendBreakRule {
    code: string;
    name: string;
    min_subtotal_minor: number;
    discount_type: 'percentage' | 'fixed';
    discount_rate_bp: number | null;
    discount_amount_minor: number | null;
    max_discount_minor: number | null;
    applies_to_contract_lines: boolean;
}

/** The `totals_context` Inertia prop (OrderPadTotalsContext.php). */
export interface TotalsContext {
    spend_breaks: SpendBreakRule[];
    carriage_paid_threshold_net_minor: number | null;
}

export interface BasketLineInput {
    /** Identifies the line to the caller — the SKU id on the pad. */
    key: string;
    baseQty: number;
    pricing: LinePricing;
}

export interface LinePrice {
    unitPriceNetE4: number;
    priceSource: PriceSource;
    appliedBreakQty: number;
    /** Pass 1's single e4 → minor conversion. */
    itemNetMinor: number;
}

export interface RecomputedLine extends LinePrice {
    key: string;
    baseQty: number;
    spendDiscountMinor: number;
    lineNetMinor: number;
    taxRateBp: number;
    lineTaxMinor: number;
    lineGrossMinor: number;
}

export interface SpendProgress {
    /** The break the next shortfall reaches, or null at the top tier / with no breaks. */
    next: { rule: SpendBreakRule; shortfallMinor: number } | null;
    /**
     * True when contract-priced lines are in the basket but do not count
     * toward the break shown — the footer says so rather than appearing
     * to miscount (05.1 §5.3).
     */
    contractLinesExcluded: boolean;
}

export interface DeliveryProgress {
    thresholdMinor: number;
    /** 0 once reached. Measured on the post-spend-break net subtotal (05.6 §6). */
    shortfallMinor: number;
    reached: boolean;
}

export interface BasketTotals {
    lines: RecomputedLine[];
    /** Lines whose quantity sits below their ladder's first rung — not priced, not counted. */
    unpricedKeys: string[];
    lineCount: number;
    totalBaseQty: number;
    /** Σ item net, before the spend break. */
    itemSubtotalMinor: number;
    spendBreak: { rule: SpendBreakRule; discountMinor: number } | null;
    /** Σ line net after the spend break — checkout preview's `subtotal_net_minor`. */
    subtotalNetMinor: number;
    taxMinor: number;
    totalGrossMinor: number;
    spendProgress: SpendProgress;
    delivery: DeliveryProgress | null;
}

/**
 * The page's pricing input for one bulk-resolve entry, or null when the
 * SKU could not be priced. Without a break table there is nothing exact
 * to recompute from, so that is null too — the pad always asks for one.
 */
export function pricingFromEntry(entry: BulkResolveEntry | undefined): LinePricing | null {
    if (entry === undefined || 'error' in entry || entry.breaks === undefined || entry.breaks.length === 0) {
        return null;
    }

    return { breaks: entry.breaks, taxRateBp: entry.resolved.tax_rate_bp };
}

/** The rung that applies at `baseQty`: greatest min_base_qty ≤ baseQty (03 §4.3). */
export function rungAt(breaks: readonly PriceBreak[], baseQty: number): PriceBreak | null {
    let best: PriceBreak | null = null;
    for (const rung of breaks) {
        if (rung.min_base_qty <= baseQty && (best === null || rung.min_base_qty > best.min_base_qty)) {
            best = rung;
        }
    }

    return best;
}

/**
 * The first rung above `baseQty` that is cheaper than the current one —
 * what the row's "add N more" prompt points at (05.1 §5.2). A rung that
 * only changes the source, or raises the price, is not a reason to buy more.
 */
export function nextCheaperRung(breaks: readonly PriceBreak[], baseQty: number): PriceBreak | null {
    const current = rungAt(breaks, baseQty);
    if (current === null) {
        return null;
    }

    let next: PriceBreak | null = null;
    for (const rung of breaks) {
        if (rung.min_base_qty > baseQty && rung.unit_price_net_e4 < current.unit_price_net_e4 && (next === null || rung.min_base_qty < next.min_base_qty)) {
            next = rung;
        }
    }

    return next;
}

/** Pass 1 for one line (OrderLinePricer, no line discount on the pad). */
export function linePrice(pricing: LinePricing, baseQty: number): LinePrice | null {
    if (!Number.isSafeInteger(baseQty) || baseQty <= 0) {
        return null;
    }

    const rung = rungAt(pricing.breaks, baseQty);
    if (rung === null) {
        return null;
    }

    return {
        unitPriceNetE4: rung.unit_price_net_e4,
        priceSource: rung.price_source,
        appliedBreakQty: rung.min_base_qty,
        itemNetMinor: lineNetMinor(rung.unit_price_net_e4, baseQty),
    };
}

/** Packs → base units (CLAUDE.md invariant 2), checked. */
export function baseQtyOf(packQty: number, packBaseUnits: number): number {
    return multiplyInts(packQty, packBaseUnits);
}

/** Packs needed to reach `baseQty`, rounded up. */
export function packsToReach(baseQty: number, packBaseUnits: number): number {
    return ceilDivInts(baseQty, packBaseUnits);
}

interface Selection {
    rule: SpendBreakRule;
    /** Whether contract lines are in the qualifying set. */
    withContract: boolean;
}

/** SpendBreakResolver::resolve() over the pre-ordered table: first rule reached. */
function firstReached(rules: readonly SpendBreakRule[], subtotalMinor: number): SpendBreakRule | null {
    return rules.find((r) => r.min_subtotal_minor <= subtotalMinor) ?? null;
}

/** OrderPricingPipeline::selectSpendBreak(), exactly. */
function selectSpendBreak(rules: readonly SpendBreakRule[], excludingContractMinor: number, includingContractMinor: number): Selection | null {
    const excluding = excludingContractMinor > 0 ? firstReached(rules, excludingContractMinor) : null;

    if (includingContractMinor === excludingContractMinor) {
        return excluding === null ? null : { rule: excluding, withContract: false };
    }

    const including = firstReached(rules, includingContractMinor);
    if (including !== null && including.applies_to_contract_lines) {
        return { rule: including, withContract: true };
    }

    return excluding === null ? null : { rule: excluding, withContract: false };
}

/** SpendBreakApportioner::discountMinor(). */
function spendDiscountMinor(rule: SpendBreakRule, qualifyingSubtotalMinor: number): number {
    if (rule.discount_type === 'percentage') {
        if (rule.discount_rate_bp === null) {
            throw new RangeError(`Spend break ${rule.code} is percentage with no rate.`);
        }
        const amount = mulDivHalfUp(qualifyingSubtotalMinor, rule.discount_rate_bp, 10000);

        return rule.max_discount_minor === null ? amount : Math.min(amount, rule.max_discount_minor);
    }

    if (rule.discount_amount_minor === null) {
        throw new RangeError(`Spend break ${rule.code} is fixed with no amount.`);
    }

    return Math.min(rule.discount_amount_minor, qualifyingSubtotalMinor);
}

/**
 * SpendBreakApportioner::apportion(): proportional half-up shares, the
 * remainder to the largest line — the first in basket order on a tie,
 * as PHP's keyOfLargest() takes the first key. Σ shares = discount, exactly.
 */
function apportion(discountMinor: number, qualifyingSubtotalMinor: number, qualifying: ReadonlyArray<{ index: number; itemNetMinor: number }>): Map<number, number> {
    const shares = new Map<number, number>();
    if (discountMinor <= 0) {
        return shares;
    }

    let largest = qualifying[0];
    for (const line of qualifying) {
        shares.set(line.index, mulDivHalfUp(discountMinor, line.itemNetMinor, qualifyingSubtotalMinor));
        if (line.itemNetMinor > largest.itemNetMinor) {
            largest = line;
        }
    }

    const remainder = subtractInts(discountMinor, sumInts(shares.values()));
    if (remainder !== 0) {
        shares.set(largest.index, sumInts([shares.get(largest.index) ?? 0, remainder]));
    }

    return shares;
}

/**
 * The next spend tier: the least additional non-contract spend Δ at
 * which selection changes to a different break. Adding Δ of such spend
 * raises both the contract-excluding and contract-including subtotals by
 * Δ, so the only Δs where selection can change are each threshold minus
 * either subtotal; each is tried in ascending order through the same
 * selectSpendBreak() the totals use.
 */
function nextSpendTier(rules: readonly SpendBreakRule[], excludingMinor: number, includingMinor: number, current: SpendBreakRule | null): SpendProgress['next'] {
    const deltas = new Set<number>();
    for (const rule of rules) {
        for (const subtotal of [excludingMinor, includingMinor]) {
            const delta = subtractInts(rule.min_subtotal_minor, subtotal);
            if (delta > 0) {
                deltas.add(delta);
            }
        }
    }

    for (const delta of [...deltas].sort((a, b) => a - b)) {
        const selection = selectSpendBreak(rules, sumInts([excludingMinor, delta]), sumInts([includingMinor, delta]));
        if (selection !== null && selection.rule !== current) {
            return { rule: selection.rule, shortfallMinor: delta };
        }
    }

    return null;
}

/**
 * The whole basket, OrderPricingPipeline's three passes. Line order
 * matters only for the apportionment remainder's tie-break; pass lines
 * in the order the cart holds them to match preview to the line.
 */
export function recomputeBasket(inputs: readonly BasketLineInput[], context: TotalsContext): BasketTotals {
    // PASS 1 — per line, item-level
    const unpricedKeys: string[] = [];
    const pass1: Array<{ input: BasketLineInput; price: LinePrice }> = [];
    for (const input of inputs) {
        const price = linePrice(input.pricing, input.baseQty);
        if (price === null) {
            unpricedKeys.push(input.key);
        } else {
            pass1.push({ input, price });
        }
    }

    // PASS 2 — order-level spend break
    const all = pass1.map((p, index) => ({ index, itemNetMinor: p.price.itemNetMinor }));
    const nonContract = all.filter((l) => pass1[l.index].price.priceSource !== 'contract');
    const includingContractMinor = sumInts(all.map((l) => l.itemNetMinor));
    const excludingContractMinor = sumInts(nonContract.map((l) => l.itemNetMinor));

    const selection = selectSpendBreak(context.spend_breaks, excludingContractMinor, includingContractMinor);
    const qualifying = selection?.withContract ? all : nonContract;
    const qualifyingSubtotalMinor = selection?.withContract ? includingContractMinor : excludingContractMinor;
    const discountMinor = selection === null ? 0 : spendDiscountMinor(selection.rule, qualifyingSubtotalMinor);
    const shares = selection === null ? new Map<number, number>() : apportion(discountMinor, qualifyingSubtotalMinor, qualifying);

    // PASS 3 — tax and totals
    const lines: RecomputedLine[] = pass1.map(({ input, price }, index) => {
        const spendDiscount = shares.get(index) ?? 0;
        const net = subtractInts(price.itemNetMinor, spendDiscount);
        const tax = lineTaxMinor(net, input.pricing.taxRateBp);

        return {
            ...price,
            key: input.key,
            baseQty: input.baseQty,
            spendDiscountMinor: spendDiscount,
            lineNetMinor: net,
            taxRateBp: input.pricing.taxRateBp,
            lineTaxMinor: tax,
            lineGrossMinor: sumInts([net, tax]),
        };
    });

    const subtotalNetMinor = sumInts(lines.map((l) => l.lineNetMinor));
    const taxMinor = sumInts(lines.map((l) => l.lineTaxMinor));

    const next = nextSpendTier(context.spend_breaks, excludingContractMinor, includingContractMinor, selection?.rule ?? null);
    const shownRule = next?.rule ?? selection?.rule ?? null;
    const hasContractLines = nonContract.length < all.length;

    const threshold = context.carriage_paid_threshold_net_minor;

    return {
        lines,
        unpricedKeys,
        lineCount: lines.length,
        totalBaseQty: sumInts(lines.map((l) => l.baseQty)),
        itemSubtotalMinor: includingContractMinor,
        spendBreak: selection === null ? null : { rule: selection.rule, discountMinor },
        subtotalNetMinor,
        taxMinor,
        // + shipping_net_minor, which is 0 (see module docblock)
        totalGrossMinor: sumInts([subtotalNetMinor, taxMinor]),
        spendProgress: {
            next,
            contractLinesExcluded: hasContractLines && shownRule !== null && !shownRule.applies_to_contract_lines,
        },
        delivery:
            threshold === null
                ? null
                : {
                      thresholdMinor: threshold,
                      shortfallMinor: Math.max(0, subtractInts(threshold, subtotalNetMinor)),
                      reached: subtotalNetMinor >= threshold,
                  },
    };
}
