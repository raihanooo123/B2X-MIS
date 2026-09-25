/**
 * Pure helpers for the goods-in entry (05.5 §4.2–4.3). No React, no I/O,
 * so they are unit-tested with `npm run test:js` (entry.test.ts).
 *
 * The server is authoritative for every rule here. These exist so the
 * screen says the same thing before the request as the server would
 * after it: the base-unit equivalent, which fields the SKU needs, and the
 * expiry warnings.
 */

export type TrackingModeValue = 'none' | 'batch' | 'serial' | 'batch_and_serial';

export function tracksBatch(mode: TrackingModeValue): boolean {
    return mode === 'batch' || mode === 'batch_and_serial';
}

export function tracksSerial(mode: TrackingModeValue): boolean {
    return mode === 'serial' || mode === 'batch_and_serial';
}

/** Integer product, refusing anything that is not a safe integer. */
export function baseQtyOf(packQty: number, baseUnits: number): number {
    if (!Number.isSafeInteger(packQty) || !Number.isSafeInteger(baseUnits) || packQty < 0 || baseUnits < 1) {
        return 0;
    }
    const product = packQty * baseUnits;

    return Number.isSafeInteger(product) ? product : 0;
}

/**
 * 05.5 §4.2's live line: "3 outers = 432 units". The pack label is used
 * as given; units are pluralised.
 */
export function packEquivalent(packQty: number, packLabel: string, baseUnits: number): string {
    const units = baseQtyOf(packQty, baseUnits);

    return `${packQty} × ${packLabel} = ${units.toLocaleString('en-GB')} ${units === 1 ? 'unit' : 'units'}`;
}

/** A whole, positive pack count typed on a keypad; null for anything else. */
export function parsePackQty(raw: string): number | null {
    const trimmed = raw.trim();
    if (!/^\d{1,7}$/.test(trimmed)) {
        return null;
    }
    const value = Number(trimmed);

    return value >= 1 ? value : null;
}

/**
 * A per-unit cost typed in pounds → `_e4` (CLAUDE.md invariant 1), by
 * string arithmetic, never a float: "0.9212" → 9212, "12.5" → 125000.
 * At most four decimal places; anything else is null.
 */
export function poundsToE4(raw: string): number | null {
    const match = /^£?\s*(\d{1,9})(?:\.(\d{1,4}))?$/.exec(raw.trim());
    if (match === null) {
        return null;
    }
    const whole = match[1];
    const fraction = (match[2] ?? '').padEnd(4, '0');

    return Number(whole) * 10000 + Number(fraction);
}

/**
 * Serials from scans, a pasted manifest or a CSV: one per line, or
 * separated by commas, semicolons or tabs. The first column of a CSV row
 * is taken; blank entries are dropped; order is kept. A header row whose
 * first cell is literally "serial" (any case) is skipped.
 */
export function parseSerials(raw: string): string[] {
    const serials: string[] = [];
    for (const row of raw.split(/\r?\n/)) {
        const cells = row.split(/[,;\t]/).map((c) => c.trim().replace(/^"(.*)"$/, '$1'));
        const isCsvRow = cells.length > 1;
        const values = isCsvRow ? [cells[0]] : cells;
        for (const value of values) {
            if (value !== '' && value.toLowerCase() !== 'serial' && value.toLowerCase() !== 'serial_number') {
                serials.push(value);
            }
        }
    }

    return serials;
}

/** Serials that appear more than once, each named once. */
export function duplicateSerials(serials: string[]): string[] {
    const seen = new Set<string>();
    const duplicates = new Set<string>();
    for (const serial of serials) {
        if (seen.has(serial)) {
            duplicates.add(serial);
        }
        seen.add(serial);
    }

    return [...duplicates];
}

export type ExpiryWarning = 'expiry_in_past' | 'expiry_beyond_horizon';

/** Y-m-d plus days, in calendar terms (UTC arithmetic on a date has no DST). */
export function addDays(ymd: string, days: number): string {
    const [y, m, d] = ymd.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));

    return date.toISOString().slice(0, 10);
}

/**
 * Mirrors ExpiryPolicy::warnings(): an expiry before the receipt date, or
 * beyond it plus the horizon, needs confirming. Y-m-d strings compare
 * correctly as strings.
 */
export function expiryWarnings(expiresOn: string, receiptDate: string, horizonDays: number): ExpiryWarning[] {
    const warnings: ExpiryWarning[] = [];
    if (expiresOn < receiptDate) {
        warnings.push('expiry_in_past');
    }
    if (expiresOn > addDays(receiptDate, horizonDays)) {
        warnings.push('expiry_beyond_horizon');
    }

    return warnings;
}

/** Today's date in the warehouse's calendar (Europe/London), Y-m-d — as ExpiryPolicy::receiptDate(). */
export function londonToday(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
}

/** A key for the entry's body: same body, same key (06 §6). */
export function entryFingerprint(value: unknown): string {
    return JSON.stringify(value);
}
