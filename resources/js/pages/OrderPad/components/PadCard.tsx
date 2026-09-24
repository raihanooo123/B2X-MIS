/**
 * One order pad row as a card, for screens below 768 px (05.1 §8.2): the
 * same row as PadRow, from the same rowParts.tsx, stacked for a phone.
 * Pack selector and quantity stepper are 44 px tall with 44 px −/+
 * targets, sitting together at the bottom of the card where a thumb
 * reaches; the line total is beside them so the effect of a tap is
 * visible without scrolling.
 */
import { memo } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { formatMinor } from '@/lib/money';
import { stockDisplay } from '@/lib/orderPad/display';
import { cn } from '@/lib/utils';

import type { PadRowProps } from './PadRow';
import { BreakList, PackPrice, PackSelector, QuantityStepper, RowNotes, StockCell, Thumbnail, usePadRow } from './rowParts';

export const PadCard = memo(function PadCard({ row, rowNumber, price, priceLoading, stock, stockLoading }: PadRowProps) {
    const r = usePadRow(row, price);

    return (
        <li className={cn('rounded-lg border bg-card p-3 text-[13px] shadow-sm', r.rejection && 'border-red-300 bg-red-50/60')}>
            <div className="flex gap-3">
                <Thumbnail url={row.thumbnail_url} alt={row.product_name} className="size-14" />
                <div className="min-w-0 flex-1">
                    <div className="font-medium leading-snug">{row.product_name}</div>
                    {row.variant_label && <div className="text-xs text-muted-foreground">{row.variant_label}</div>}
                    <div className="mt-0.5 font-mono text-xs text-muted-foreground">
                        <span className="sr-only">Row {rowNumber}, SKU </span>
                        {row.sku_code}
                    </div>
                </div>
                <div className="shrink-0">
                    {r.pack === undefined ? null : priceLoading && price === undefined ? (
                        <Skeleton className="h-4 w-16" />
                    ) : r.pricing === null ? (
                        <span className="text-xs text-muted-foreground">Price unavailable</span>
                    ) : (
                        <PackPrice pricing={r.pricing} pack={r.pack} packQty={r.draft.packQty} />
                    )}
                </div>
            </div>

            <div className="mt-2 flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">{r.pack && r.pricing && <BreakList pricing={r.pricing} pack={r.pack} packQty={r.draft.packQty} />}</div>
                <div className="shrink-0">{stockLoading ? <Skeleton className="h-4 w-20" /> : r.pack && <StockCell display={stockDisplay(stock, r.pack)} />}</div>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <div className="min-w-32 flex-1">
                    {r.pack === undefined ? (
                        <span className="text-xs text-muted-foreground">No sellable pack</span>
                    ) : (
                        <PackSelector
                            packs={row.packs}
                            value={r.pack.code}
                            onChange={r.selectPack}
                            open={r.packOpen}
                            onOpenChange={r.setPackOpen}
                            returnFocusTo={r.qtyRef}
                            size="touch"
                        />
                    )}
                </div>
                <QuantityStepper
                    value={r.draft.packQty}
                    onChange={r.changeQty}
                    disabled={r.pack === undefined}
                    label={r.qtyLabel}
                    inputRef={r.qtyRef}
                    onOpenPacks={row.packs.length > 1 ? () => r.setPackOpen(true) : undefined}
                    invalid={r.rejection !== undefined}
                    size="touch"
                />
            </div>

            <div className="mt-1.5 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <RowNotes prompt={r.prompt} rejection={r.rejection} />
                </div>
                <div className="shrink-0 text-right tabular-nums">
                    <span className="text-xs text-muted-foreground">Line </span>
                    {r.line !== null ? <span className="font-semibold">{formatMinor(r.line.itemNetMinor)}</span> : <span className="text-muted-foreground">—</span>}
                </div>
            </div>
        </li>
    );
});
