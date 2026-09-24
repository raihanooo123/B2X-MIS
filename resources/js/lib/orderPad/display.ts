/**
 * Pure display derivations for one order pad row — no React, no fetching.
 * Integer arithmetic only, through lib/money.ts (CLAUDE.md invariant 1).
 *
 * Prices are read off the SKU's effective ladder with
 * lib/pricing/localRecompute.ts — the same functions the footer totals
 * use — so the row and the footer can never disagree about which break
 * applies.
 */
import type { PriceBreak, StockAvailabilityEntry } from '@/lib/api/orderPad';
import { formatE4, formatMinor, lineNetMinor, subtractInts } from '@/lib/money';
import { baseQtyOf, nextCheaperRung, packsToReach, rungAt } from '@/lib/pricing/localRecompute';

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

/**
 * Price of one selected pack for display (03 §5) at the break the row
 * has reached: unit price at `baseQty` × base_units, rounded half-up to
 * pence once. `baseQty` is the typed quantity, or one pack when the row
 * is empty. Display only — never used for line arithmetic (03 §5).
 */
export function packPriceDisplay(breaks: readonly PriceBreak[], pack: PadPack, baseQty: number) {
    const rung = rungAt(breaks, Math.max(baseQty, pack.base_units));
    if (rung === null) {
        return null;
    }

    return {
        unitPriceE4: rung.unit_price_net_e4,
        pack: formatMinor(lineNetMinor(rung.unit_price_net_e4, pack.base_units)),
        unit: formatE4(rung.unit_price_net_e4),
    };
}

export interface PackBreakRow {
    packQty: number;
    unitPrice: string;
    packPrice: string;
}

/**
 * The break ladder "translated from base units" into the selected pack
 * (05.1 §4.2): each rung becomes the number of packs needed to reach it,
 * rounded up. Thresholds that land on the same price are collapsed, and
 * the first row is always "1+" — the price of a single pack.
 */
export function packBreakRows(breaks: readonly PriceBreak[], pack: PadPack): PackBreakRow[] {
    const thresholds = new Set<number>([1]);
    for (const b of breaks) {
        thresholds.add(Math.max(1, packsToReach(b.min_base_qty, pack.base_units)));
    }

    const rows: PackBreakRow[] = [];
    let previousE4: number | null = null;

    for (const packQty of [...thresholds].sort((a, b) => a - b)) {
        const rung = rungAt(breaks, baseQtyOf(packQty, pack.base_units));
        if (rung === null || rung.unit_price_net_e4 === previousE4) {
            continue;
        }
        previousE4 = rung.unit_price_net_e4;
        rows.push({
            packQty,
            unitPrice: formatE4(rung.unit_price_net_e4),
            packPrice: formatMinor(lineNetMinor(rung.unit_price_net_e4, pack.base_units)),
        });
    }

    return rows;
}

/**
 * The row's nudge toward the next cheaper break (05.1 §5.2), in packs:
 * "Add 2 more for £0.86 each" for single units, "Add 1 more outer for
 * £0.86/unit" otherwise. The price quoted is the one actually reached by
 * the rounded-up pack count, which may be a later rung still.
 */
export function nextBreakPrompt(breaks: readonly PriceBreak[], pack: PadPack, packQty: number): string | null {
    const baseQty = baseQtyOf(packQty, pack.base_units);
    const next = nextCheaperRung(breaks, baseQty);
    if (next === null) {
        return null;
    }

    const targetPacks = packsToReach(next.min_base_qty, pack.base_units);
    const reached = rungAt(breaks, baseQtyOf(targetPacks, pack.base_units));
    if (reached === null) {
        return null;
    }

    const more = subtractInts(targetPacks, packQty);
    const price = formatE4(reached.unit_price_net_e4);

    return pack.base_units === 1
        ? `Add ${more.toLocaleString('en-GB')} more for ${price} each`
        : `Add ${more.toLocaleString('en-GB')} more ${packNoun(pack, more)} for ${price}/unit`;
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
