/**
 * Unit tests for the goods-in entry helpers. Run with `npm run test:js`.
 * Expiry cases mirror ExpiryPolicy (05.5 §4.3); cost parsing mirrors
 * CLAUDE.md invariant 1 — integer e4, no floats.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { addDays, baseQtyOf, duplicateSerials, expiryWarnings, londonToday, packEquivalent, parsePackQty, parseSerials, poundsToE4 } from './entry.ts';

describe('pack conversion', () => {
    it('converts packs to base units at every pack level', () => {
        assert.equal(baseQtyOf(3, 144), 432);
        assert.equal(baseQtyOf(1, 1), 1);
        assert.equal(baseQtyOf(0, 12), 0);
        assert.equal(packEquivalent(3, 'Outer of 144', 144), '3 × Outer of 144 = 432 units');
        assert.equal(packEquivalent(1, 'Each', 1), '1 × Each = 1 unit');
    });

    it('accepts only whole positive pack counts', () => {
        assert.equal(parsePackQty('43'), 43);
        assert.equal(parsePackQty(' 7 '), 7);
        assert.equal(parsePackQty('0'), null);
        assert.equal(parsePackQty('4.5'), null);
        assert.equal(parsePackQty('-1'), null);
        assert.equal(parsePackQty(''), null);
    });
});

describe('poundsToE4', () => {
    it('parses pounds to integer ten-thousandths without floats', () => {
        assert.equal(poundsToE4('0.9212'), 9212);
        assert.equal(poundsToE4('12.5'), 125000);
        assert.equal(poundsToE4('£3'), 30000);
        assert.equal(poundsToE4('0.1'), 1000);
    });

    it('refuses more than four decimals, negatives and text', () => {
        assert.equal(poundsToE4('0.12345'), null);
        assert.equal(poundsToE4('-1'), null);
        assert.equal(poundsToE4('abc'), null);
        assert.equal(poundsToE4(''), null);
    });
});

describe('serial capture', () => {
    it('reads scans, a pasted list and a CSV manifest', () => {
        assert.deepEqual(parseSerials('SN1\nSN2\n\nSN3'), ['SN1', 'SN2', 'SN3']);
        assert.deepEqual(parseSerials('serial,model\nSN1,X\n"SN2",Y'), ['SN1', 'SN2']);
        assert.deepEqual(parseSerials('SN1\tA\r\nSN2\tB'), ['SN1', 'SN2']);
    });

    it('names duplicates once', () => {
        assert.deepEqual(duplicateSerials(['A', 'B', 'A', 'A', 'C', 'B']), ['A', 'B']);
        assert.deepEqual(duplicateSerials(['A', 'B']), []);
    });
});

describe('expiryWarnings', () => {
    it('warns on a past date and beyond the horizon, not in between', () => {
        assert.deepEqual(expiryWarnings('2026-09-24', '2026-09-25', 3650), ['expiry_in_past']);
        assert.deepEqual(expiryWarnings('2026-09-25', '2026-09-25', 3650), []);
        assert.deepEqual(expiryWarnings('2036-09-22', '2026-09-25', 3650), []);
        assert.deepEqual(expiryWarnings('2036-09-23', '2026-09-25', 3650), ['expiry_beyond_horizon']);
    });

    it('adds calendar days across month and year ends', () => {
        assert.equal(addDays('2026-12-31', 1), '2027-01-01');
        assert.equal(addDays('2028-02-28', 1), '2028-02-29');
    });

    it("takes today's date in Europe/London, not UTC", () => {
        // 23:30 UTC on 25 September is 00:30 BST on the 26th.
        assert.equal(londonToday(new Date('2026-09-25T23:30:00Z')), '2026-09-26');
        // In winter London is UTC.
        assert.equal(londonToday(new Date('2026-12-25T23:30:00Z')), '2026-12-25');
    });
});
