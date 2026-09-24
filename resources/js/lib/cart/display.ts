/**
 * Showing cart, checkout and order figures excluding or including VAT
 * (`display_mode`, PriceDisplay.php): trade buyers ex-VAT, public
 * customers inc-VAT. Integer arithmetic only, through lib/money.ts
 * (CLAUDE.md invariant 1).
 *
 * Line and order totals are always the server's own figures — preview's
 * or the order's snapshot — chosen, never recomputed: `line_net_minor`
 * or `line_gross_minor`, `subtotal_net_minor` or `total_gross_minor`.
 * They already carry any spend discount (03 §7A.3), so lines sum exactly
 * to the totals beneath them.
 *
 * The one derived figure is the per-pack price in gross mode: unit net
 * price × (1 + VAT rate), shown to the pack and rounded once for display.
 * It is never used for a total (03 §5).
 */
import { formatE4, formatMinor, lineNetMinor, mulDivHalfUp, sumInts } from '@/lib/money';

export type DisplayMode = 'net' | 'gross';

export function vatLabel(mode: DisplayMode): string {
    return mode === 'gross' ? 'inc. VAT' : 'ex. VAT';
}

/** A line's total as the server priced it, in the chosen mode. */
export function lineTotalMinor(line: { line_net_minor: number | null; line_gross_minor: number | null }, mode: DisplayMode): number | null {
    return mode === 'gross' ? line.line_gross_minor : line.line_net_minor;
}

/** Unit price per base unit in e4, in the chosen mode (display only). */
export function unitPriceE4(unitNetE4: number, taxRateBp: number, mode: DisplayMode): number {
    return mode === 'gross' ? mulDivHalfUp(unitNetE4, sumInts([10000, taxRateBp]), 10000) : unitNetE4;
}

/** Price of one pack, in the chosen mode, for display (03 §5). */
export function packPrice(unitNetE4: number, taxRateBp: number, baseUnits: number, mode: DisplayMode): string {
    return formatMinor(lineNetMinor(unitPriceE4(unitNetE4, taxRateBp, mode), baseUnits));
}

export function unitPrice(unitNetE4: number, taxRateBp: number, mode: DisplayMode): string {
    return formatE4(unitPriceE4(unitNetE4, taxRateBp, mode));
}

export interface TotalsRow {
    label: string;
    value: string;
    tone?: 'discount' | 'muted' | 'strong';
}

/**
 * The totals block, in the order a buyer reads it. Ex-VAT: subtotal, VAT,
 * total. Inc-VAT: total first, with the VAT it includes. Either way the
 * spend discount (already inside the figures) is stated, and delivery is
 * noted as confirmed later — shipping is 0 until 05.6's rating exists.
 */
export function totalsRows(
    totals: { subtotal_net_minor: number; tax_minor: number; total_gross_minor: number; spend_break_discount_minor: number },
    mode: DisplayMode,
): TotalsRow[] {
    const rows: TotalsRow[] = [];

    if (totals.spend_break_discount_minor > 0) {
        rows.push({ label: 'Spend discount (included)', value: `−${formatMinor(totals.spend_break_discount_minor)}`, tone: 'discount' });
    }

    if (mode === 'net') {
        rows.push({ label: 'Subtotal (ex. VAT)', value: formatMinor(totals.subtotal_net_minor) });
        rows.push({ label: 'VAT', value: formatMinor(totals.tax_minor) });
        rows.push({ label: 'Total', value: formatMinor(totals.total_gross_minor), tone: 'strong' });
    } else {
        rows.push({ label: 'Total (inc. VAT)', value: formatMinor(totals.total_gross_minor), tone: 'strong' });
        rows.push({ label: 'Includes VAT of', value: formatMinor(totals.tax_minor), tone: 'muted' });
    }

    return rows;
}
