/**
 * Order pad (doc 05.1) — the table.
 *
 * Rows are one keyset page of active SKUs, passed as Inertia props by
 * OrderPadController. Prices and stock for exactly those SKUs are fetched
 * through the Part 1 hooks (one bulk-resolve and one availability call for
 * the whole page — 05.1 §9's Q-A/Q-B and Q-C), so the page makes a fixed
 * number of requests however many rows it shows.
 *
 * Row numbers continue across pages: the server returns the first row's
 * number with each page, carried in the (opaque) cursor rather than
 * counted.
 *
 * Not yet: local recompute, sticky footer, search/filters, bulk entry.
 */
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, PackageSearch, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useBulkResolve, useStockAvailability, type BulkResolveEntry, type StockAvailabilityEntry } from '@/lib/api/orderPad';

import { PadRow } from './components/PadRow';
import type { OrderPadProps } from './types';

export default function OrderPadIndex({ catalogue, page_size }: OrderPadProps) {
    const { rows, start_row, next_cursor } = catalogue;
    const skuIds = useMemo(() => rows.map((r) => r.sku_id), [rows]);
    const navigating = useInertiaNavigating();

    const prices = useBulkResolve(skuIds);
    const stock = useStockAvailability(skuIds);

    const priceBySku = useMemo(() => indexBySku<BulkResolveEntry>(prices.data?.data), [prices.data]);
    const stockBySku = useMemo(() => indexBySku<StockAvailabilityEntry>(stock.data?.data), [stock.data]);

    const lastRow = start_row + rows.length - 1;

    return (
        <>
            <Head title="Order pad" />

            <div className="mx-auto max-w-[1400px] px-4 py-4">
                <header className="mb-3 flex items-baseline justify-between gap-4">
                    <h1 className="text-lg font-semibold tracking-tight">Order pad</h1>
                    {rows.length > 0 && (
                        <p className="text-xs tabular-nums text-muted-foreground">
                            Rows {start_row.toLocaleString('en-GB')}–{lastRow.toLocaleString('en-GB')}
                        </p>
                    )}
                </header>

                {(prices.isError || stock.isError) && (
                    <div role="alert" className="mb-3 flex items-center justify-between gap-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        <span>
                            {prices.isError ? 'Prices' : 'Stock levels'} couldn't be loaded. The rest of the pad still works.
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-7"
                            onClick={() => {
                                if (prices.isError) void prices.refetch();
                                if (stock.isError) void stock.refetch();
                            }}
                        >
                            <RotateCcw /> Retry
                        </Button>
                    </div>
                )}

                {rows.length === 0 && !navigating ? (
                    <EmptyState onLaterPage={start_row > 1} />
                ) : (
                    <div className="rounded-md border">
                        <Table>
                            <TableHeader className="sticky top-0 z-10 bg-background">
                                <TableRow className="text-xs hover:bg-transparent">
                                    <TableHead className="h-8 w-10 pr-0 text-right">#</TableHead>
                                    <TableHead className="h-8 w-12">
                                        <span className="sr-only">Image</span>
                                    </TableHead>
                                    <TableHead className="h-8">SKU</TableHead>
                                    <TableHead className="h-8">Product</TableHead>
                                    <TableHead className="h-8">Pack</TableHead>
                                    <TableHead className="h-8 text-right">Price</TableHead>
                                    <TableHead className="h-8">Breaks</TableHead>
                                    <TableHead className="h-8">Stock</TableHead>
                                    <TableHead className="h-8">Qty</TableHead>
                                    <TableHead className="h-8 text-right">Line</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {navigating
                                    ? Array.from({ length: Math.min(page_size, 12) }, (_, i) => <SkeletonRow key={i} />)
                                    : rows.map((row, i) => (
                                          <PadRow
                                              key={row.sku_id}
                                              row={row}
                                              rowNumber={start_row + i}
                                              price={priceBySku.get(row.sku_id)}
                                              priceLoading={prices.isPending || prices.isPlaceholderData}
                                              stock={stockBySku.get(row.sku_id)}
                                              stockLoading={stock.isPending || stock.isPlaceholderData}
                                          />
                                      ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                <nav className="mt-3 flex items-center justify-between text-sm" aria-label="Pages">
                    {start_row > 1 ? (
                        <Link href="/order-pad" className="text-muted-foreground hover:text-foreground">
                            Back to first page
                        </Link>
                    ) : (
                        <span />
                    )}
                    {next_cursor !== null && (
                        <Button asChild variant="outline" size="sm">
                            <Link href="/order-pad" data={{ after: next_cursor }} preserveState={false}>
                                Next {page_size} <ChevronRight />
                            </Link>
                        </Button>
                    )}
                </nav>
            </div>
        </>
    );
}

function indexBySku<T extends { sku_id: string }>(entries: T[] | undefined): Map<string, T> {
    return new Map((entries ?? []).map((e) => [e.sku_id, e]));
}

/** True while an Inertia visit (e.g. the next page) is in flight. */
function useInertiaNavigating(): boolean {
    const [navigating, setNavigating] = useState(false);

    useEffect(() => {
        const offStart = router.on('start', () => setNavigating(true));
        const offFinish = router.on('finish', () => setNavigating(false));

        return () => {
            offStart();
            offFinish();
        };
    }, []);

    return navigating;
}

function SkeletonRow() {
    return (
        <TableRow className="hover:bg-transparent">
            <TableCell className="py-1.5">
                <Skeleton className="ml-auto h-3 w-5" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="size-9" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="h-3 w-16" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="h-4 w-48" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="h-8 w-32" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="ml-auto h-4 w-16" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="h-3 w-28" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="h-4 w-20" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="h-8 w-28" />
            </TableCell>
            <TableCell className="py-1.5">
                <Skeleton className="ml-auto h-4 w-12" />
            </TableCell>
        </TableRow>
    );
}

function EmptyState({ onLaterPage }: { onLaterPage: boolean }) {
    return (
        <div className="flex flex-col items-center rounded-md border border-dashed px-6 py-16 text-center">
            <PackageSearch className="mb-3 size-8 text-muted-foreground" aria-hidden />
            {onLaterPage ? (
                <>
                    <h2 className="font-medium">You've reached the end of the catalogue</h2>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">There are no more products after the last page you viewed.</p>
                    <Button asChild variant="outline" size="sm" className="mt-4">
                        <Link href="/order-pad">Back to the first page</Link>
                    </Button>
                </>
            ) : (
                <>
                    <h2 className="font-medium">No products to order yet</h2>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                        Products appear here once they're active and have at least one active SKU. If you expected to see items, contact
                        your account manager.
                    </p>
                </>
            )}
        </div>
    );
}
