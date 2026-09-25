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

/** The carriage line (05.6 §8), when a destination is known. */
export interface DeliveryLine {
    status: 'rated' | 'free' | 'manual_quote' | 'unserviceable';
    zone_name: string | null;
    method: string | null;
    shipping_net_minor: number | null;
    shipping_tax_minor: number | null;
}

function deliveryLabel(d: DeliveryLine): string {
    const where = [d.zone_name, d.method].filter(Boolean).join(', ');

    return where ? `Delivery — ${where}` : 'Delivery';
}

function deliveryValue(d: DeliveryLine, mode: DisplayMode): string {
    if (d.status === 'free') {
        return 'Free';
    }
    if (d.status !== 'rated' || d.shipping_net_minor === null) {
        return 'Quoted separately';
    }

    return formatMinor(mode === 'gross' ? sumInts([d.shipping_net_minor, d.shipping_tax_minor ?? 0]) : d.shipping_net_minor);
}

/**
 * The totals block, in the order a buyer reads it. Ex-VAT: subtotal,
 * delivery, VAT, total. Inc-VAT: delivery, total, then the VAT it
 * includes. The spend discount (already inside the figures) is stated;
 * carriage is shown before payment, never revealed at the end (05.6 §8).
 * Without a known destination the delivery line says it is confirmed at
 * checkout.
 */
export function totalsRows(
    totals: { subtotal_net_minor: number; tax_minor: number; total_gross_minor: number; spend_break_discount_minor: number },
    mode: DisplayMode,
    delivery: DeliveryLine | null = null,
): TotalsRow[] {
    const rows: TotalsRow[] = [];

    if (totals.spend_break_discount_minor > 0) {
        rows.push({ label: 'Spend discount (included)', value: `−${formatMinor(totals.spend_break_discount_minor)}`, tone: 'discount' });
    }

    const deliveryRow: TotalsRow = delivery
        ? { label: `${deliveryLabel(delivery)} (${vatLabel(mode)})`, value: deliveryValue(delivery, mode), tone: delivery.status === 'free' ? 'discount' : undefined }
        : { label: 'Delivery', value: 'At checkout', tone: 'muted' };

    if (mode === 'net') {
        rows.push({ label: 'Subtotal (ex. VAT)', value: formatMinor(totals.subtotal_net_minor) });
        rows.push(deliveryRow);
        rows.push({ label: 'VAT', value: formatMinor(totals.tax_minor) });
        rows.push({ label: 'Total', value: formatMinor(totals.total_gross_minor), tone: 'strong' });
    } else {
        rows.push(deliveryRow);
        rows.push({ label: 'Total (inc. VAT)', value: formatMinor(totals.total_gross_minor), tone: 'strong' });
        rows.push({ label: 'Includes VAT of', value: formatMinor(totals.tax_minor), tone: 'muted' });
    }

    return rows;
}
