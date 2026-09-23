/**
 * Pure display derivations for one order pad row — no React, no fetching.
 * Integer arithmetic only, through lib/money.ts (CLAUDE.md invariant 1).
 *
 * Deliberately NOT here: recomputing line totals or the footer as the
 * quantity changes (05.1 §5.1). That is lib/pricing/localRecompute.ts's
 * job (ROADMAP §5), which must mirror OrderLinePricer exactly. What is
 * here is quantity-independent: the price of *one* selected pack, the
 * break ladder translated into packs, and the stock figure in pack units.
 */
import type { PriceBreak, StockAvailabilityEntry } from '@/lib/api/orderPad';
import { formatE4, formatMinor, lineNetMinor } from '@/lib/money';

export interface PadPack {
    code: string;
    label: string;
    pack_level: 'each' | 'inner' | 'outer' | 'pallet';
    base_units: number;
}

/**
 * Integer floor division for non-negative operands. Subtracting the
 * remainder first makes the division exact, so no fractional
 * intermediate ever exists.
 */
function floorDiv(numerator: number, denominator: number): number {
    return (numerator - (numerator % denominator)) / denominator;
}

function ceilDiv(numerator: number, denominator: number): number {
    return floorDiv(numerator + denominator - 1, denominator);
}

/**
 * The unit price that applies at `baseQty`: the break with the highest
 * `min_base_qty` not exceeding it (03 §4.4). `fallbackE4` — the price
 * resolved at base_qty 1 — covers an absent break table.
 */
export function unitPriceE4At(breaks: readonly PriceBreak[] | undefined, fallbackE4: number, baseQty: number): number {
    let price = fallbackE4;
    let bestMin = -1;

    for (const b of breaks ?? []) {
        if (b.min_base_qty <= baseQty && b.min_base_qty > bestMin) {
            bestMin = b.min_base_qty;
            price = b.unit_price_net_e4;
        }
    }

    return price;
}

/**
 * Price of one selected pack for display (03 §5): unit price at the
 * pack's own base quantity × base_units, rounded half-up to pence once.
 * Display only — never used for line arithmetic (03 §5).
 */
export function packPriceDisplay(breaks: readonly PriceBreak[] | undefined, fallbackE4: number, pack: PadPack) {
    const unitE4 = unitPriceE4At(breaks, fallbackE4, pack.base_units);

    return {
        unitPriceE4: unitE4,
        pack: formatMinor(lineNetMinor(unitE4, pack.base_units)),
        unit: formatE4(unitE4),
    };
}

export interface PackBreakRow {
    packQty: number;
    unitPrice: string;
    packPrice: string;
}

/**
 * The break ladder "translated from base units" into the selected pack
 * (05.1 §4.2): each break becomes the number of packs needed to reach it,
 * rounded up. Thresholds that land on the same price are collapsed, and
 * the first row is always "1+" — the price of a single pack.
 */
export function packBreakRows(breaks: readonly PriceBreak[] | undefined, fallbackE4: number, pack: PadPack): PackBreakRow[] {
    const thresholds = new Set<number>([1]);
    for (const b of breaks ?? []) {
        thresholds.add(Math.max(1, ceilDiv(b.min_base_qty, pack.base_units)));
    }

    const rows: PackBreakRow[] = [];
    let previousE4: number | null = null;

    for (const packQty of [...thresholds].sort((a, b) => a - b)) {
        const unitE4 = unitPriceE4At(breaks, fallbackE4, packQty * pack.base_units);
        if (unitE4 === previousE4) {
            continue;
        }
        previousE4 = unitE4;
        rows.push({
            packQty,
            unitPrice: formatE4(unitE4),
            packPrice: formatMinor(lineNetMinor(unitE4, pack.base_units)),
        });
    }

    return rows;
}

const PLURALS: Record<PadPack['pack_level'], [string, string]> = {
    each: ['unit', 'units'],
    inner: ['inner', 'inners'],
    outer: ['outer', 'outers'],
    pallet: ['pallet', 'pallets'],
};

export function packNoun(pack: PadPack, count: number): string {
    const [one, many] = PLURALS[pack.pack_level] ?? ['pack', 'packs'];

    return count === 1 ? one : many;
}

export type StockTone = 'in' | 'part' | 'backorder' | 'out' | 'untracked' | 'unknown';

export interface StockDisplay {
    tone: StockTone;
    /** The figure, in the selected pack's units — null when none is shown. */
    figure: string | null;
    /** Always present: colour is never the only signal (05.1 §4.2). */
    label: string;
    detail: string | null;
}

function formatEta(isoDate: string): string {
    const [y, m, d] = isoDate.split('-');
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[Number.parseInt(m ?? '', 10) - 1];

    return month && d && y ? `${Number.parseInt(d, 10)} ${month} ${y}` : isoDate;
}

/**
 * 05.1 §4.3, exactly. Exact figures, never banded ("low stock").
 * The back-in-stock signup for "Out of stock" waits on
 * `back_in_stock_subscriptions` (ROADMAP §0.3, not migrated).
 */
export function stockDisplay(entry: StockAvailabilityEntry | undefined, pack: PadPack): StockDisplay {
    if (entry === undefined || 'error' in entry) {
        return { tone: 'unknown', figure: null, label: 'Unavailable', detail: null };
    }

    if (!entry.is_stock_tracked) {
        return { tone: 'untracked', figure: null, label: 'Not stock-tracked', detail: null };
    }

    const available = Math.max(0, entry.available_base_qty);

    if (available >= pack.base_units) {
        const packs = floorDiv(available, pack.base_units);

        return { tone: 'in', figure: `${packs.toLocaleString('en-GB')} ${packNoun(pack, packs)}`, label: 'In stock', detail: null };
    }

    if (available > 0) {
        return {
            tone: 'part',
            figure: `${available.toLocaleString('en-GB')} ${available === 1 ? 'unit' : 'units'}`,
            label: 'Part case only',
            detail: null,
        };
    }

    if (entry.allow_backorder) {
        const eta = entry.incoming?.expected_on ? `Due ${formatEta(entry.incoming.expected_on)}` : null;

        return { tone: 'backorder', figure: null, label: 'On backorder', detail: eta };
    }

    return { tone: 'out', figure: null, label: 'Out of stock', detail: null };
}
