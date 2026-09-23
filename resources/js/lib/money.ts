/**
 * Integer-only money helpers — the client-side mirror of
 * app/Domain/Pricing/Money.php (CLAUDE.md invariant 1, doc 03 §6, doc 06 §3.1).
 *
 * Two scales, identified by the field suffix and never mixed in one
 * expression (06 §3.1 rule 2):
 *   - `_e4`    per-unit amounts, ten-thousandths of a pound. £0.9212 → 9212
 *   - `_minor` per-line / per-document amounts, whole pence
 *
 * No floating-point arithmetic happens here. Inputs are JSON integers
 * (safe below 2^53, 06 §3.1 rule 4) and are checked to be safe integers;
 * every multiplication and division runs on BigInt, whose `/` truncates
 * toward zero and whose `%` takes the dividend's sign — exactly PHP's
 * `intdiv()` and `%`, so roundHalfUpDiv() matches Money::roundHalfUpDiv()
 * bit for bit, including its behaviour on negative numerators.
 *
 * Formatting is for display only. A formatted string is never parsed back
 * into a number (06 §3.1).
 *
 * GBP only: multi-currency is an open decision (CLAUDE.md), so the symbol
 * is fixed rather than guessed from a locale.
 */

const CURRENCY_SYMBOL = '£';

function toBig(value: number, name: string): bigint {
    if (!Number.isSafeInteger(value)) {
        throw new RangeError(`${name} must be a safe integer, got ${value}.`);
    }

    return BigInt(value);
}

function toSafeNumber(value: bigint, name: string): number {
    if (value > BigInt(Number.MAX_SAFE_INTEGER) || value < BigInt(Number.MIN_SAFE_INTEGER)) {
        throw new RangeError(`${name} is outside the safe integer range: ${value}.`);
    }

    return Number(value);
}

function roundHalfUpDivBig(numerator: bigint, denominator: bigint): bigint {
    if (denominator <= 0n) {
        throw new RangeError(`denominator must be positive, got ${denominator}.`);
    }

    const q = numerator / denominator;
    const r = numerator % denominator;

    return r * 2n >= denominator ? q + 1n : q;
}

/**
 * Half-up integer division: 0.5 rounds away from zero. Mirrors
 * Money::roundHalfUpDiv(), including its assumption of a non-negative
 * numerator (a negative one truncates, as it does in PHP).
 */
export function roundHalfUpDiv(numerator: number, denominator: number): number {
    return toSafeNumber(
        roundHalfUpDivBig(toBig(numerator, 'numerator'), toBig(denominator, 'denominator')),
        'result',
    );
}

/**
 * The single `e4 → minor` conversion for a line (03 §6.1, 06 §3.1 rule 3):
 * round_half_up(unit_price_e4 × base_qty / 100). The product is taken in
 * BigInt so a large quantity cannot lose precision before rounding.
 */
export function lineNetMinor(unitPriceNetE4: number, baseQty: number): number {
    return toSafeNumber(
        roundHalfUpDivBig(toBig(unitPriceNetE4, 'unitPriceNetE4') * toBig(baseQty, 'baseQty'), 100n),
        'lineNetMinor',
    );
}

/**
 * Tax on a line's net value: round_half_up(net_minor × rate_bp / 10000),
 * as OrderPricingPipeline computes it (03 §7A.4 Pass 3).
 */
export function lineTaxMinor(lineNetMinorValue: number, taxRateBp: number): number {
    return toSafeNumber(
        roundHalfUpDivBig(toBig(lineNetMinorValue, 'lineNetMinor') * toBig(taxRateBp, 'taxRateBp'), 10000n),
        'lineTaxMinor',
    );
}

function groupThousands(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function formatScaled(value: bigint, scale: bigint, fractionDigits: number, minFractionDigits: number): string {
    const negative = value < 0n;
    const abs = negative ? -value : value;

    const pounds = groupThousands((abs / scale).toString());
    let fraction = (abs % scale).toString().padStart(fractionDigits, '0');

    while (fraction.length > minFractionDigits && fraction.endsWith('0')) {
        fraction = fraction.slice(0, -1);
    }

    return `${negative ? '-' : ''}${CURRENCY_SYMBOL}${pounds}.${fraction}`;
}

/**
 * A `_minor` amount for display: 132653 → "£1,326.53".
 */
export function formatMinor(amountMinor: number): string {
    return formatScaled(toBig(amountMinor, 'amountMinor'), 100n, 2, 2);
}

/**
 * An `_e4` per-unit amount for display. Always at least two decimal
 * places, and up to four when the value has them — trailing zeros
 * beyond the pence are dropped, never rounded away:
 * 8600 → "£0.86", 9250 → "£0.925", 9212 → "£0.9212".
 */
export function formatE4(amountE4: number): string {
    return formatScaled(toBig(amountE4, 'amountE4'), 10000n, 4, 2);
}
