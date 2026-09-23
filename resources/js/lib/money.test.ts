/**
 * Unit tests for money.ts. Run with `npm run test:js` — Node's built-in
 * test runner, which executes TypeScript directly (Node ≥ 23.6 type
 * stripping), so no extra test dependency is needed.
 *
 * roundHalfUpDiv cases mirror Money::roundHalfUpDiv(); the line/tax
 * figures are 06 §3.1's own worked example (9212 e4 × 1440 → 132653
 * minor, 20% tax → 26531 minor).
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { formatE4, formatMinor, lineNetMinor, lineTaxMinor, roundHalfUpDiv } from './money.ts';

describe('roundHalfUpDiv', () => {
    it('rounds exact halves up and everything else to nearest', () => {
        assert.equal(roundHalfUpDiv(5, 10), 1);
        assert.equal(roundHalfUpDiv(4, 10), 0);
        assert.equal(roundHalfUpDiv(15, 10), 2);
        assert.equal(roundHalfUpDiv(14, 10), 1);
        assert.equal(roundHalfUpDiv(0, 7), 0);
        assert.equal(roundHalfUpDiv(100, 100), 1);
        assert.equal(roundHalfUpDiv(13265280, 100), 132653);
    });

    it('handles an odd denominator without a fractional midpoint', () => {
        assert.equal(roundHalfUpDiv(3, 3), 1);
        assert.equal(roundHalfUpDiv(4, 3), 1);
        assert.equal(roundHalfUpDiv(5, 3), 2);
    });

    it('mirrors PHP for a negative numerator (truncating intdiv, sign-following %)', () => {
        // PHP: intdiv(-15, 10) = -1, -15 % 10 = -5, (-5 * 2 >= 10) is false → -1
        assert.equal(roundHalfUpDiv(-15, 10), -1);
        assert.equal(roundHalfUpDiv(-4, 10), 0);
    });

    it('rejects a non-positive denominator, non-integers and unsafe integers', () => {
        assert.throws(() => roundHalfUpDiv(1, 0), RangeError);
        assert.throws(() => roundHalfUpDiv(1, -1), RangeError);
        assert.throws(() => roundHalfUpDiv(1.5, 10), RangeError);
        assert.throws(() => roundHalfUpDiv(10, 2.5), RangeError);
        assert.throws(() => roundHalfUpDiv(Number.MAX_SAFE_INTEGER + 1, 10), RangeError);
        assert.throws(() => roundHalfUpDiv(Number.NaN, 10), RangeError);
    });

    it('always returns an integer', () => {
        for (let n = 0; n < 1000; n += 7) {
            for (const d of [1, 3, 7, 100, 10000]) {
                assert.ok(Number.isInteger(roundHalfUpDiv(n, d)));
            }
        }
    });
});

describe('lineNetMinor / lineTaxMinor', () => {
    it('matches 06 §3.1’s worked example', () => {
        const net = lineNetMinor(9212, 1440);
        assert.equal(net, 132653);
        assert.equal(lineTaxMinor(net, 2000), 26531);
    });

    it('converts e4 → minor once, rounding half up', () => {
        assert.equal(lineNetMinor(9212, 1), 92); // 0.9212 → 92.12p → 92
        assert.equal(lineNetMinor(9250, 1), 93); // 92.5p → 93
        assert.equal(lineNetMinor(0, 500), 0);
        assert.equal(lineTaxMinor(0, 2000), 0);
        assert.equal(lineTaxMinor(25, 2000), 5);
        assert.equal(lineTaxMinor(12, 500), 1); // 0.6p → 1
    });

    it('keeps precision when unit × qty exceeds 2^53 before division', () => {
        // 9_007_199_254_740_991 × 100 overflows a double; BigInt keeps it exact.
        assert.equal(lineNetMinor(Number.MAX_SAFE_INTEGER, 100), Number.MAX_SAFE_INTEGER);
    });

    it('refuses a result outside the safe integer range rather than returning a rounded double', () => {
        assert.throws(() => lineNetMinor(Number.MAX_SAFE_INTEGER, 1000), RangeError);
    });

    it('rejects fractional inputs', () => {
        assert.throws(() => lineNetMinor(92.12, 1), RangeError);
        assert.throws(() => lineTaxMinor(100, 20.5), RangeError);
    });
});

describe('formatMinor', () => {
    it('formats whole pence as pounds with thousands separators', () => {
        assert.equal(formatMinor(132653), '£1,326.53');
        assert.equal(formatMinor(0), '£0.00');
        assert.equal(formatMinor(5), '£0.05');
        assert.equal(formatMinor(100), '£1.00');
        assert.equal(formatMinor(99999), '£999.99');
        assert.equal(formatMinor(123456789), '£1,234,567.89');
    });

    it('puts the sign before the symbol', () => {
        assert.equal(formatMinor(-500), '-£5.00');
        assert.equal(formatMinor(-5), '-£0.05');
    });

    it('rejects fractional input', () => {
        assert.throws(() => formatMinor(1.5), RangeError);
    });
});

describe('formatE4', () => {
    it('shows at least pence and up to four decimals, trimming only trailing zeros', () => {
        assert.equal(formatE4(9212), '£0.9212');
        assert.equal(formatE4(8600), '£0.86');
        assert.equal(formatE4(9250), '£0.925');
        assert.equal(formatE4(10000), '£1.00');
        assert.equal(formatE4(1), '£0.0001');
        assert.equal(formatE4(0), '£0.00');
        assert.equal(formatE4(12345678), '£1,234.5678');
    });

    it('puts the sign before the symbol', () => {
        assert.equal(formatE4(-8600), '-£0.86');
    });

    it('rejects fractional input', () => {
        assert.throws(() => formatE4(92.12), RangeError);
    });
});
