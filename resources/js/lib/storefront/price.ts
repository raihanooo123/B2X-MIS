/**
 * Storefront unit prices (05.15 §4.3). Stored and resolved prices are net.
 * Inc VAT, a unit price is `round_half_up(net_e4 × (10000 + rate_bp) / 10000)`
 * and is shown to the penny, the way a consumer reads a shelf price. Ex VAT
 * keeps the trade convention of up to four decimals (lib/money.ts formatE4).
 * Integer arithmetic only (CLAUDE.md invariant 1). Line and order totals are
 * never derived here: the cart shows the server's own figures.
 */
import { unitPriceE4, type DisplayMode } from '@/lib/cart/display';
import { formatE4, formatMinor, roundHalfUpDiv } from '@/lib/money';

export function shelfPrice(unitNetE4: number, taxRateBp: number, mode: DisplayMode): string {
    const e4 = unitPriceE4(unitNetE4, taxRateBp, mode);

    return mode === 'gross' ? formatMinor(roundHalfUpDiv(e4, 100)) : formatE4(e4);
}
