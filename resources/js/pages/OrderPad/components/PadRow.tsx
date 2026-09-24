/**
 * One order pad row, desktop table layout (05.1 §4.2): row number,
 * thumbnail, SKU code, product name, pack selector, price, break table,
 * stock, quantity, line total. PadCard is the same row below 768 px;
 * both are built from rowParts.tsx.
 *
 * Prices come from /pricing/bulk-resolve, stock from /stock/availability;
 * both are passed in so the page fetches once for all rows.
 *
 * Typing a quantity recomputes locally (05.1 §5.1): the price cell moves
 * to the reached break and marks it, the line total is the item net from
 * lib/pricing/localRecompute.ts — plus its VAT when prices are shown
 * inc-VAT (`mode`, as on cart and checkout) — (before any order-wide spend discount,
 * which the footer shows), and a prompt names the next cheaper break
 * (05.1 §5.2). No request per keystroke.
 */
import { memo } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { TableCell, TableRow } from '@/components/ui/table';
import type { BulkResolveEntry, StockAvailabilityEntry } from '@/lib/api/orderPad';
import type { DisplayMode } from '@/lib/cart/display';
import { formatMinor } from '@/lib/money';
import { stockDisplay } from '@/lib/orderPad/display';
import { cn } from '@/lib/utils';

import type { PadRowData } from '../types';
import { BreakList, PackPrice, PackSelector, QuantityStepper, RowNotes, StockCell, Thumbnail, usePadRow } from './rowParts';

export interface PadRowProps {
    row: PadRowData;
    rowNumber: number;
    price: BulkResolveEntry | undefined;
    priceLoading: boolean;
    stock: StockAvailabilityEntry | undefined;
    stockLoading: boolean;
    /** Ex- or inc-VAT (PriceDisplay.php), as on the cart and checkout. */
    mode: DisplayMode;
}

export const PadRow = memo(function PadRow({ row, rowNumber, price, priceLoading, stock, stockLoading, mode }: PadRowProps) {
    const r = usePadRow(row, price, mode);

    return (
        <TableRow className={cn('text-[13px]', r.rejection && 'bg-red-50/60 hover:bg-red-50')}>
            <TableCell className="w-10 py-1.5 pr-0 text-right tabular-nums text-muted-foreground">{rowNumber}</TableCell>

            <TableCell className="w-12 py-1.5">
                <Thumbnail url={row.thumbnail_url} alt={row.product_name} />
            </TableCell>

            <TableCell className="w-28 whitespace-nowrap py-1.5 font-mono text-xs">{row.sku_code}</TableCell>

            <TableCell className="min-w-48 py-1.5">
                <div className="font-medium leading-tight">{row.product_name}</div>
                {row.variant_label && <div className="text-xs leading-tight text-muted-foreground">{row.variant_label}</div>}
            </TableCell>

            <TableCell className="w-40 py-1.5">
                <PackSelector
                    packs={row.packs}
                    value={r.pack?.code ?? null}
                    onChange={r.selectPack}
                    open={r.packOpen}
                    onOpenChange={r.setPackOpen}
                    returnFocusTo={r.qtyRef}
                    size="dense"
                />
            </TableCell>

            {r.pack === undefined ? (
                <TableCell colSpan={2} className="py-1.5 text-xs text-muted-foreground">
                    No sellable pack
                </TableCell>
            ) : priceLoading && price === undefined ? (
                <>
                    <TableCell className="w-28 py-1.5">
                        <Skeleton className="ml-auto h-4 w-16" />
                        <Skeleton className="ml-auto mt-1 h-3 w-12" />
                    </TableCell>
                    <TableCell className="w-44 py-1.5">
                        <Skeleton className="h-3 w-28" />
                        <Skeleton className="mt-1 h-3 w-24" />
                    </TableCell>
                </>
            ) : r.pricing === null ? (
                <TableCell colSpan={2} className="py-1.5 text-xs text-muted-foreground" title={price && 'error' in price ? price.error.message : undefined}>
                    Price unavailable
                </TableCell>
            ) : (
                <>
                    <TableCell className="w-28 py-1.5">
                        <PackPrice pricing={r.pricing} pack={r.pack} packQty={r.draft.packQty} mode={mode} />
                    </TableCell>
                    <TableCell className="w-44 py-1.5">
                        <BreakList pricing={r.pricing} pack={r.pack} packQty={r.draft.packQty} mode={mode} />
                    </TableCell>
                </>
            )}

            <TableCell className="w-32 py-1.5">
                {stockLoading ? <Skeleton className="h-4 w-20" /> : r.pack && <StockCell display={stockDisplay(stock, r.pack)} />}
            </TableCell>

            <TableCell className="w-32 py-1.5">
                <QuantityStepper
                    value={r.draft.packQty}
                    onChange={r.changeQty}
                    disabled={r.pack === undefined}
                    label={r.qtyLabel}
                    inputRef={r.qtyRef}
                    onOpenPacks={row.packs.length > 1 ? () => r.setPackOpen(true) : undefined}
                    invalid={r.rejection !== undefined}
                    size="dense"
                />
                <RowNotes prompt={r.prompt} rejection={r.rejection} />
            </TableCell>

            <TableCell className="w-24 py-1.5 text-right tabular-nums">
                {r.lineTotalMinor !== null ? <span className="font-medium">{formatMinor(r.lineTotalMinor)}</span> : <span className="text-muted-foreground">—</span>}
            </TableCell>
        </TableRow>
    );
});
