/**
 * Driver for tests/Feature/OrderPad/LocalRecomputeParityTest.php: runs
 * the order pad's real lib/pricing/localRecompute.ts (not a port of it)
 * over baskets the PHP test also sends through checkout preview, and
 * prints what the client would show. Node ≥ 23.6 runs this TypeScript
 * directly.
 *
 * stdin:  { context: TotalsContext, entries: BulkResolveEntry[],
 *           baskets: { sku_id, pack_qty, pack_base_units }[][] }
 * stdout: one result per basket, keys named as checkout preview names them.
 */
import type { BulkResolveEntry } from '../../resources/js/lib/api/orderPad.ts';
import { baseQtyOf, pricingFromEntry, recomputeBasket, type TotalsContext } from '../../resources/js/lib/pricing/localRecompute.ts';

interface Input {
    context: TotalsContext;
    entries: BulkResolveEntry[];
    baskets: { sku_id: string; pack_qty: number; pack_base_units: number }[][];
}

const chunks: Buffer[] = [];
for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
}
const input = JSON.parse(Buffer.concat(chunks).toString('utf8')) as Input;

const entryBySku = new Map(input.entries.map((e) => [e.sku_id, e]));

const results = input.baskets.map((basket) => {
    const totals = recomputeBasket(
        basket.map((line) => {
            const pricing = pricingFromEntry(entryBySku.get(line.sku_id));
            if (pricing === null) {
                throw new Error(`No pricing for ${line.sku_id}`);
            }

            return { key: line.sku_id, baseQty: baseQtyOf(line.pack_qty, line.pack_base_units), pricing };
        }),
        input.context,
    );

    return {
        subtotal_net_minor: totals.subtotalNetMinor,
        tax_minor: totals.taxMinor,
        total_gross_minor: totals.totalGrossMinor,
        spend_break: totals.spendBreak === null ? null : { code: totals.spendBreak.rule.code, discount_minor: totals.spendBreak.discountMinor },
        lines: totals.lines.map((l) => ({
            sku_id: l.key,
            base_qty: l.baseQty,
            unit_price_net_e4: l.unitPriceNetE4,
            price_source: l.priceSource,
            line_spend_discount_minor: l.spendDiscountMinor,
            line_net_minor: l.lineNetMinor,
            tax_rate_bp: l.taxRateBp,
            line_tax_minor: l.lineTaxMinor,
            line_gross_minor: l.lineGrossMinor,
        })),
        unpriced: totals.unpricedKeys,
    };
});

process.stdout.write(JSON.stringify(results));
