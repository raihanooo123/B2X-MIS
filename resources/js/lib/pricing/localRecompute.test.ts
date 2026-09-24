/**
 * Unit tests for localRecompute.ts (`npm run test:js`). Fixtures are the
 * specs' own: 03 §7A.5's worked example, 03 §7A.7's edge cases, 05.1
 * §5.2's next-break nudge. Parity with the real server is proven
 * separately, end to end, by tests/Feature/OrderPad/LocalRecomputeParityTest.php.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import type { PriceBreak } from '../api/orderPad.ts';
import { linePrice, nextCheaperRung, recomputeBasket, rungAt, type LinePricing, type SpendBreakRule, type TotalsContext } from './localRecompute.ts';

const grater: PriceBreak[] = [
    { min_base_qty: 1, unit_price_net_e4: 9800, price_source: 'tier' },
    { min_base_qty: 144, unit_price_net_e4: 9212, price_source: 'tier' },
    { min_base_qty: 1440, unit_price_net_e4: 8600, price_source: 'tier' },
];

/** A single-rung ladder whose line at qty 1 is exactly `netMinor`. */
function flat(netMinor: number, taxRateBp: number, source: PriceBreak['price_source'] = 'base'): LinePricing {
    return { breaks: [{ min_base_qty: 1, unit_price_net_e4: netMinor * 100, price_source: source }], taxRateBp };
}

function rule(overrides: Partial<SpendBreakRule> & Pick<SpendBreakRule, 'code' | 'min_subtotal_minor'>): SpendBreakRule {
    return {
        name: overrides.code,
        discount_type: 'percentage',
        discount_rate_bp: 300,
        discount_amount_minor: null,
        max_discount_minor: null,
        applies_to_contract_lines: false,
        ...overrides,
    };
}

const noBreaks: TotalsContext = { spend_breaks: [], carriage_paid_threshold_net_minor: 50000 };

describe('rungAt / linePrice', () => {
    it('picks the greatest threshold not exceeding the quantity (03 §4.3)', () => {
        assert.equal(rungAt(grater, 1)?.unit_price_net_e4, 9800);
        assert.equal(rungAt(grater, 143)?.unit_price_net_e4, 9800);
        assert.equal(rungAt(grater, 144)?.unit_price_net_e4, 9212);
        assert.equal(rungAt(grater, 1439)?.unit_price_net_e4, 9212);
        assert.equal(rungAt(grater, 1440)?.unit_price_net_e4, 8600);
        assert.equal(rungAt(grater, 0), null);
    });

    it('converts e4 → minor once per line, from the unit price, not a rounded pack price (03 §5)', () => {
        const line = linePrice({ breaks: grater, taxRateBp: 2000 }, 1440);
        assert.deepEqual(line, { unitPriceNetE4: 8600, priceSource: 'tier', appliedBreakQty: 1440, itemNetMinor: 123840 });
        assert.equal(linePrice({ breaks: grater, taxRateBp: 2000 }, 1441)?.itemNetMinor, 123926); // 1441 × 0.86 = £1,239.26
        assert.equal(linePrice({ breaks: grater, taxRateBp: 2000 }, 143)?.itemNetMinor, 14014); // 143 × 0.98 = £140.14
        assert.equal(linePrice({ breaks: grater, taxRateBp: 2000 }, 145)?.itemNetMinor, 13357); // 145 × 0.9212 = £133.574 → £133.57
    });

    it('follows a source change on the effective ladder (a contract list from 50 only)', () => {
        const ladder: PriceBreak[] = [
            { min_base_qty: 1, unit_price_net_e4: 11999, price_source: 'tier' },
            { min_base_qty: 50, unit_price_net_e4: 10001, price_source: 'contract' },
        ];
        assert.equal(linePrice({ breaks: ladder, taxRateBp: 500 }, 49)?.priceSource, 'tier');
        assert.equal(linePrice({ breaks: ladder, taxRateBp: 500 }, 50)?.priceSource, 'contract');
    });
});

describe('nextCheaperRung', () => {
    it('points at the next cheaper break, and at nothing from the best one', () => {
        assert.equal(nextCheaperRung(grater, 142)?.min_base_qty, 144);
        assert.equal(nextCheaperRung(grater, 144)?.min_base_qty, 1440);
        assert.equal(nextCheaperRung(grater, 1440), null);
    });

    it('skips a rung that is not cheaper', () => {
        const ladder: PriceBreak[] = [
            { min_base_qty: 1, unit_price_net_e4: 5000, price_source: 'tier' },
            { min_base_qty: 10, unit_price_net_e4: 5000, price_source: 'contract' },
            { min_base_qty: 20, unit_price_net_e4: 5200, price_source: 'customer' },
            { min_base_qty: 30, unit_price_net_e4: 4800, price_source: 'customer' },
        ];
        assert.equal(nextCheaperRung(ladder, 1)?.min_base_qty, 30);
    });
});

describe('recomputeBasket', () => {
    it('reproduces 03 §7A.5’s worked example exactly', () => {
        const totals = recomputeBasket(
            [
                { key: 'A', baseQty: 1, pricing: flat(62000, 2000) },
                { key: 'B', baseQty: 1, pricing: flat(31000, 2000) },
                { key: 'C', baseQty: 1, pricing: flat(15000, 0) },
            ],
            { spend_breaks: [rule({ code: '3pc-1000', min_subtotal_minor: 100000 })], carriage_paid_threshold_net_minor: null },
        );

        assert.deepEqual(
            totals.lines.map((l) => [l.spendDiscountMinor, l.lineNetMinor, l.lineTaxMinor, l.lineGrossMinor]),
            [
                [1860, 60140, 12028, 72168],
                [930, 30070, 6014, 36084],
                [450, 14550, 0, 14550],
            ],
        );
        assert.equal(totals.spendBreak?.discountMinor, 3240);
        assert.equal(totals.itemSubtotalMinor, 108000);
        assert.equal(totals.subtotalNetMinor, 104760);
        assert.equal(totals.taxMinor, 18042);
        assert.equal(totals.totalGrossMinor, 122802);
        assert.equal(totals.delivery, null);
    });

    it('applies no break one penny below the threshold (§7A.7 #13)', () => {
        const totals = recomputeBasket([{ key: 'A', baseQty: 1, pricing: flat(99999, 2000) }], {
            spend_breaks: [rule({ code: '3pc-1000', min_subtotal_minor: 100000 })],
            carriage_paid_threshold_net_minor: null,
        });
        assert.equal(totals.spendBreak, null);
        assert.deepEqual(totals.spendProgress.next && [totals.spendProgress.next.rule.code, totals.spendProgress.next.shortfallMinor], ['3pc-1000', 1]);
    });

    it('caps a percentage break at max_discount_minor (§7A.7 #15)', () => {
        const totals = recomputeBasket([{ key: 'A', baseQty: 1, pricing: flat(500000, 2000) }], {
            spend_breaks: [rule({ code: 'capped', min_subtotal_minor: 100000, discount_rate_bp: 500, max_discount_minor: 9000 })],
            carriage_paid_threshold_net_minor: null,
        });
        assert.equal(totals.spendBreak?.discountMinor, 9000);
    });

    it('excludes contract lines from the subtotal and the apportionment (§7A.7 #16)', () => {
        const totals = recomputeBasket(
            [
                { key: 'contract', baseQty: 1, pricing: flat(90000, 2000, 'contract') },
                { key: 'tier', baseQty: 1, pricing: flat(60000, 2000, 'tier') },
            ],
            { spend_breaks: [rule({ code: '3pc-1000', min_subtotal_minor: 100000 })], carriage_paid_threshold_net_minor: null },
        );
        assert.equal(totals.spendBreak, null, '£600 of non-contract spend does not reach £1,000');
        assert.equal(totals.spendProgress.next?.shortfallMinor, 40000);
        assert.equal(totals.spendProgress.contractLinesExcluded, true);
    });

    it('lets a break that applies to contract lines qualify on the contract-inclusive subtotal', () => {
        const totals = recomputeBasket(
            [
                { key: 'contract', baseQty: 1, pricing: flat(90000, 2000, 'contract') },
                { key: 'tier', baseQty: 1, pricing: flat(60000, 2000, 'tier') },
            ],
            {
                spend_breaks: [rule({ code: 'incl', min_subtotal_minor: 100000, applies_to_contract_lines: true })],
                carriage_paid_threshold_net_minor: null,
            },
        );
        assert.equal(totals.spendBreak?.discountMinor, 4500);
        assert.deepEqual(
            totals.lines.map((l) => l.spendDiscountMinor),
            [2700, 1800],
        );
        assert.equal(totals.spendProgress.contractLinesExcluded, false);
    });

    it('puts the remainder penny on the largest line, first on a tie (§7A.7 #17)', () => {
        const totals = recomputeBasket(
            [
                { key: 'A', baseQty: 1, pricing: flat(33334, 0) },
                { key: 'B', baseQty: 1, pricing: flat(33333, 0) },
                { key: 'C', baseQty: 1, pricing: flat(33333, 0) },
            ],
            { spend_breaks: [rule({ code: 'fixed', min_subtotal_minor: 100000, discount_type: 'fixed', discount_rate_bp: null, discount_amount_minor: 100 })], carriage_paid_threshold_net_minor: null },
        );
        const shares = totals.lines.map((l) => l.spendDiscountMinor);
        assert.equal(shares.reduce((a, b) => a + b, 0), 100);
        assert.deepEqual(shares, [34, 33, 33]);
    });

    it('clamps a fixed discount to the subtotal (§7A.7 #19)', () => {
        const totals = recomputeBasket([{ key: 'A', baseQty: 1, pricing: flat(1500, 2000) }], {
            spend_breaks: [rule({ code: 'big', min_subtotal_minor: 1000, discount_type: 'fixed', discount_rate_bp: null, discount_amount_minor: 5000 })],
            carriage_paid_threshold_net_minor: null,
        });
        assert.equal(totals.spendBreak?.discountMinor, 1500);
        assert.equal(totals.subtotalNetMinor, 0);
        assert.equal(totals.totalGrossMinor, 0);
    });

    it('selects the first reached break in table order and names the next tier', () => {
        const context: TotalsContext = {
            spend_breaks: [rule({ code: 'five', min_subtotal_minor: 200000, discount_rate_bp: 500 }), rule({ code: 'three', min_subtotal_minor: 100000 })],
            carriage_paid_threshold_net_minor: null,
        };
        const totals = recomputeBasket([{ key: 'A', baseQty: 1, pricing: flat(184260, 2000) }], context);
        assert.equal(totals.spendBreak?.rule.code, 'three');
        assert.equal(totals.spendProgress.next?.rule.code, 'five');
        assert.equal(totals.spendProgress.next?.shortfallMinor, 15740);
    });

    it('measures free delivery on the post-discount net subtotal (05.6 §6)', () => {
        const totals = recomputeBasket([{ key: 'A', baseQty: 1, pricing: flat(51000, 2000) }], {
            spend_breaks: [rule({ code: 'ten', min_subtotal_minor: 50000, discount_rate_bp: 1000 })],
            carriage_paid_threshold_net_minor: 50000,
        });
        assert.equal(totals.subtotalNetMinor, 45900);
        assert.deepEqual(totals.delivery, { thresholdMinor: 50000, shortfallMinor: 4100, reached: false });
    });

    it('counts lines and base units, and reports quantities below the first rung as unpriced', () => {
        const late: LinePricing = { breaks: [{ min_base_qty: 10, unit_price_net_e4: 100, price_source: 'base' }], taxRateBp: 0 };
        const totals = recomputeBasket(
            [
                { key: 'A', baseQty: 144, pricing: { breaks: grater, taxRateBp: 2000 } },
                { key: 'B', baseQty: 5, pricing: late },
            ],
            noBreaks,
        );
        assert.equal(totals.lineCount, 1);
        assert.equal(totals.totalBaseQty, 144);
        assert.deepEqual(totals.unpricedKeys, ['B']);
    });

    it('returns integers only, and recomputes a 500-line basket inside the 50 ms budget', () => {
        const context: TotalsContext = {
            spend_breaks: [rule({ code: 'five', min_subtotal_minor: 2000000, discount_rate_bp: 500 }), rule({ code: 'three', min_subtotal_minor: 100000, discount_rate_bp: 333 })],
            carriage_paid_threshold_net_minor: 50000,
        };
        const lines = Array.from({ length: 500 }, (_, i) => ({
            key: `sku-${i}`,
            baseQty: 1 + ((i * 37) % 2000),
            pricing: { breaks: grater.map((b) => ({ ...b, price_source: i % 7 === 0 ? ('contract' as const) : b.price_source })), taxRateBp: [2000, 500, 0][i % 3] },
        }));

        recomputeBasket(lines, context); // warm up
        const started = performance.now();
        const totals = recomputeBasket(lines, context);
        const elapsed = performance.now() - started;

        assert.ok(elapsed < 50, `took ${elapsed} ms`);
        for (const value of [totals.subtotalNetMinor, totals.taxMinor, totals.totalGrossMinor, ...totals.lines.flatMap((l) => [l.lineNetMinor, l.lineTaxMinor, l.spendDiscountMinor])]) {
            assert.ok(Number.isSafeInteger(value));
        }
    });
});
