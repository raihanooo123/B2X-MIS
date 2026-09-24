/**
 * The order pad's sticky footer (05.1 §4.1, §5.3; 05.6 §8): line count,
 * units, subtotal, VAT and total for every quantity typed on the pad,
 * across pages, recomputed locally on each change with
 * lib/pricing/localRecompute.ts — the same calculation checkout preview
 * makes, so the figures agree to the penny.
 *
 * Free-delivery progress and spend-break progress are two separate
 * indicators with their own icon and wording. They coincide often enough
 * that merging them would confuse (05.6 §8), and they measure different
 * subtotals: delivery the post-discount net, the spend break its
 * qualifying subtotal (contract lines excluded unless the break says
 * otherwise, stated rather than silently miscounted).
 *
 * Totals are net of VAT where labelled; nothing is locked in before
 * submit (05.1 §10) — the server re-resolves at checkout.
 */
import { CircleCheck, Percent, Truck } from 'lucide-react';
import { useMemo, type ReactNode } from 'react';

import { formatBasisPoints, formatMinor, mulDivHalfUp, subtractInts } from '@/lib/money';
import { baseQtyOf, recomputeBasket, type BasketLineInput, type SpendBreakRule, type TotalsContext } from '@/lib/pricing/localRecompute';
import { cn } from '@/lib/utils';
import { useOrderPadStore } from '@/stores/orderPadStore';

function useBasketTotals(context: TotalsContext) {
    const drafts = useOrderPadStore((s) => s.drafts);
    const pricing = useOrderPadStore((s) => s.pricing);

    return useMemo(() => {
        const inputs: BasketLineInput[] = [];
        let pending = 0;

        for (const [skuId, draft] of Object.entries(drafts)) {
            if (draft.packQty === null || draft.packBaseUnits === null) {
                continue;
            }
            const linePricing = pricing[skuId];
            if (linePricing === undefined || linePricing === null) {
                pending += 1;
                continue;
            }
            inputs.push({ key: skuId, baseQty: baseQtyOf(draft.packQty, draft.packBaseUnits), pricing: linePricing });
        }

        const totals = recomputeBasket(inputs, context);

        return { totals, unpricedCount: pending + totals.unpricedKeys.length };
    }, [drafts, pricing, context]);
}

function discountLabel(rule: SpendBreakRule): string {
    return rule.discount_type === 'percentage' && rule.discount_rate_bp !== null
        ? `${formatBasisPoints(rule.discount_rate_bp)} off your order`
        : `${formatMinor(rule.discount_amount_minor ?? 0)} off your order`;
}

/** Whole-percent progress for a bar, integer arithmetic, clamped to 0–100. */
function percentOf(value: number, target: number): number {
    if (target <= 0) {
        return 100;
    }

    return Math.min(100, Math.max(0, mulDivHalfUp(Math.max(0, value), 100, target)));
}

export function StickyFooter({ context }: { context: TotalsContext }) {
    const { totals, unpricedCount } = useBasketTotals(context);
    const { spendBreak, spendProgress, delivery } = totals;
    const hasSpendBreaks = context.spend_breaks.length > 0;

    return (
        <footer className="fixed inset-x-0 bottom-0 z-20 border-t bg-background/95 shadow-[0_-1px_3px_rgba(0,0,0,0.04)] backdrop-blur supports-[backdrop-filter]:bg-background/85">
            <div className="mx-auto flex max-w-[1400px] flex-col gap-3 px-4 py-2.5 text-sm md:flex-row md:items-center md:justify-between md:gap-6">
                <div className="min-w-0 space-y-1.5 md:max-w-[46%]">
                    <p className="tabular-nums text-muted-foreground">
                        <span className="font-medium text-foreground">{totals.lineCount.toLocaleString('en-GB')}</span> {totals.lineCount === 1 ? 'line' : 'lines'}
                        {' · '}
                        <span className="font-medium text-foreground">{totals.totalBaseQty.toLocaleString('en-GB')}</span> {totals.totalBaseQty === 1 ? 'unit' : 'units'}
                        {unpricedCount > 0 && <span className="ml-2 text-amber-700">· {unpricedCount} not priced yet</span>}
                    </p>

                    {delivery && (
                        <Indicator
                            icon={<Truck className="size-3.5" aria-hidden />}
                            reached={delivery.reached}
                            percent={percentOf(totals.subtotalNetMinor, delivery.thresholdMinor)}
                            tone="sky"
                            label={
                                delivery.reached ? (
                                    'Free delivery reached'
                                ) : (
                                    <>
                                        Spend <strong className="font-semibold">{formatMinor(delivery.shortfallMinor)}</strong> more (ex. VAT) for free delivery
                                    </>
                                )
                            }
                        />
                    )}

                    {hasSpendBreaks && (
                        <Indicator
                            icon={<Percent className="size-3.5" aria-hidden />}
                            reached={spendBreak !== null && spendProgress.next === null}
                            percent={spendProgress.next === null ? 100 : percentOf(subtractInts(spendProgress.next.rule.min_subtotal_minor, spendProgress.next.shortfallMinor), spendProgress.next.rule.min_subtotal_minor)}
                            tone="violet"
                            label={
                                <>
                                    {spendBreak !== null && (
                                        <span>
                                            {discountLabel(spendBreak.rule)} applied (−{formatMinor(spendBreak.discountMinor)})
                                            {spendProgress.next !== null && ' · '}
                                        </span>
                                    )}
                                    {spendProgress.next !== null && (
                                        <span>
                                            Spend <strong className="font-semibold">{formatMinor(spendProgress.next.shortfallMinor)}</strong> more for{' '}
                                            {discountLabel(spendProgress.next.rule)}
                                        </span>
                                    )}
                                    {spendProgress.contractLinesExcluded && (
                                        <span className="block text-xs text-muted-foreground">Contract-priced lines don't count toward this offer.</span>
                                    )}
                                </>
                            }
                        />
                    )}
                </div>

                <dl className="grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5 self-end tabular-nums md:self-auto" aria-live="polite">
                    <dt className="text-muted-foreground">Subtotal (ex. VAT)</dt>
                    <dd className="text-right">{formatMinor(totals.subtotalNetMinor)}</dd>
                    {spendBreak !== null && spendBreak.discountMinor > 0 && (
                        <>
                            <dt className="text-xs text-muted-foreground">incl. spend discount</dt>
                            <dd className="text-right text-xs text-emerald-700">−{formatMinor(spendBreak.discountMinor)}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">VAT</dt>
                    <dd className="text-right">{formatMinor(totals.taxMinor)}</dd>
                    <dt className="font-semibold">Total</dt>
                    <dd className="text-right text-base font-semibold">{formatMinor(totals.totalGrossMinor)}</dd>
                    <dd className="col-span-2 text-right text-[11px] text-muted-foreground">Delivery confirmed at checkout</dd>
                </dl>
            </div>
        </footer>
    );
}

const TONES = {
    sky: { bar: 'bg-sky-500', track: 'bg-sky-100', icon: 'text-sky-700' },
    violet: { bar: 'bg-violet-500', track: 'bg-violet-100', icon: 'text-violet-700' },
} as const;

function Indicator({ icon, label, reached, percent, tone }: { icon: ReactNode; label: ReactNode; reached: boolean; percent: number; tone: keyof typeof TONES }) {
    const t = TONES[tone];

    return (
        <div className="flex items-start gap-2">
            <span className={cn('mt-0.5 shrink-0', reached ? 'text-emerald-600' : t.icon)}>{reached ? <CircleCheck className="size-3.5" aria-hidden /> : icon}</span>
            <div className="min-w-0 flex-1">
                <p className={cn('text-xs leading-snug sm:text-[13px]', reached && 'text-emerald-800')}>{label}</p>
                <div className={cn('mt-1 h-1 w-full max-w-72 overflow-hidden rounded-full', t.track)} aria-hidden>
                    <div className={cn('h-full rounded-full', reached ? 'bg-emerald-500' : t.bar)} style={{ width: `${percent}%` }} />
                </div>
            </div>
        </div>
    );
}
