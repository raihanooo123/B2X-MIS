import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatUkDate, formatUkDateTime } from './dateTime.ts';

test('formats UK dates in the display timezone, not UTC', () => {
    // 23:30 UTC on 4 October is 00:30 BST on 5 October.
    assert.equal(formatUkDate('2026-10-04T23:30:00Z', 'Europe/London'), '05/10/2026');
    assert.equal(formatUkDate(null), '');
});

test('formats UK date-times on the 24-hour clock, with the zone only when asked', () => {
    assert.equal(formatUkDateTime('2026-10-05T13:30:00Z', 'Europe/London'), '05/10/2026, 14:30');
    assert.equal(formatUkDateTime('2026-10-05T13:30:00Z', 'Europe/London', true), '05/10/2026, 14:30 BST');
    // After the clocks go back (25 October 2026) London is on GMT.
    assert.equal(formatUkDateTime('2026-11-05T13:30:00Z', 'Europe/London', true), '05/11/2026, 13:30 GMT');
});
