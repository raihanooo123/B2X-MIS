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
 * Quantities recompute locally (05.1 §5.1): each bulk-resolve answer is
 * remembered per SKU in the pad store, so the sticky footer totals every
 * typed row — including rows on pages already left — with no request.
 *
 * Search and filters (PadToolbar) are server-side partial reloads; the
 * keyboard contract (05.1 §8.1) lives in rowParts.tsx and
 * lib/keyboard/tabOrder.ts. Below 768 px rows render as cards (PadCard,
 * 05.1 §8.2) — one layout at a time, so quantity fields appear once in
 * the tab order. While a new page or filter loads, the current rows stay
 * in place, dimmed, so focus is never stolen by an async update.
 *
 * Not yet: paste/CSV bulk entry, saved lists, barcode scanning.
 */
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, PackageSearch, RotateCcw, X } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useBulkResolve, useStockAvailability, type BulkResolveEntry, type StockAvailabilityEntry } from '@/lib/api/orderPad';
import { pricingFromEntry, type LinePricing } from '@/lib/pricing/localRecompute';
import { DESKTOP_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { vatLabel } from '@/lib/cart/display';
import { cn } from '@/lib/utils';
import { useOrderPadStore } from '@/stores/orderPadStore';

import { PadCard } from './components/PadCard';
import { PadRow } from './components/PadRow';
import { filterQuery, hasActiveFilters, PadToolbar, visitWithFilters } from './components/PadToolbar';
import { StickyFooter } from './components/StickyFooter';
import type { OrderPadProps, PadFacets, PadFilters } from './types';

export default function OrderPadIndex({ catalogue, filters, facets, page_size, totals_context, display_mode }: OrderPadProps) {
    const { rows, start_row, next_cursor } = catalogue;
    const skuIds = useMemo(() => rows.map((r) => r.sku_id), [rows]);
    const navigating = useInertiaNavigating();
    const desktop = useMediaQuery(DESKTOP_QUERY);

    const prices = useBulkResolve(skuIds);
    const stock = useStockAvailability(skuIds);

    const priceBySku = useMemo(() => indexBySku<BulkResolveEntry>(prices.data?.data), [prices.data]);
    const stockBySku = useMemo(() => indexBySku<StockAvailabilityEntry>(stock.data?.data), [stock.data]);

    const rememberPricing = useOrderPadStore((s) => s.rememberPricing);
    useEffect(() => {
        if (prices.data === undefined) {
            return;
        }
        const bySku: Record<string, LinePricing | null> = {};
        for (const entry of prices.data.data) {
            bySku[entry.sku_id] = pricingFromEntry(entry);
        }
        rememberPricing(bySku);
    }, [prices.data, rememberPricing]);

    const lastRow = start_row + rows.length - 1;
    const rowData = (skuId: string) => ({
        mode: display_mode,
        price: priceBySku.get(skuId),
        priceLoading: prices.isPending || prices.isPlaceholderData,
        stock: stockBySku.get(skuId),
        stockLoading: stock.isPending || stock.isPlaceholderData,
    });

    return (
        <>
            <Head title="Order pad" />

            <div className="mx-auto max-w-[1400px] px-4 pb-56 pt-4 md:pb-36">
                <header className="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="flex items-baseline gap-4">
                        <h1 className="text-lg font-semibold tracking-tight">Order pad</h1>
                        {rows.length > 0 && (
                            <p className="text-xs tabular-nums text-muted-foreground">
                                Rows {start_row.toLocaleString('en-GB')}–{lastRow.toLocaleString('en-GB')}
                            </p>
                        )}
                    </div>
                    <AccountMenu />
                </header>

                <PadToolbar filters={filters} facets={facets} />
                <p className="mb-2 hidden text-[11px] text-muted-foreground md:block">
                    <Kbd>Tab</Kbd> next item · <Kbd>↑</Kbd>
                    <Kbd>↓</Kbd> one pack more / fewer · <Kbd>Enter</Kbd> next row · <Kbd>Esc</Kbd> undo changes · <Kbd>Alt</Kbd>+<Kbd>↓</Kbd> pack size ·{' '}
                    <Kbd>/</Kbd> search
                </p>

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
                    <EmptyState onLaterPage={start_row > 1} filters={filters} facets={facets} />
                ) : desktop ? (
                    <div className={cn('rounded-md border transition-opacity', navigating && 'opacity-60')} aria-busy={navigating}>
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
                                    <TableHead className="h-8 text-right">Price ({vatLabel(display_mode)})</TableHead>
                                    <TableHead className="h-8">Breaks</TableHead>
                                    <TableHead className="h-8">Stock</TableHead>
                                    <TableHead className="h-8">Qty</TableHead>
                                    <TableHead className="h-8 text-right">Line</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, i) => (
                                    <PadRow key={row.sku_id} row={row} rowNumber={start_row + i} {...rowData(row.sku_id)} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                ) : (
                    <ul className={cn('space-y-2 transition-opacity', navigating && 'opacity-60')} aria-busy={navigating} aria-label="Products">
                        {rows.map((row, i) => (
                            <PadCard key={row.sku_id} row={row} rowNumber={start_row + i} {...rowData(row.sku_id)} />
                        ))}
                    </ul>
                )}

                <nav className="mt-3 flex items-center justify-between text-sm" aria-label="Pages">
                    {start_row > 1 ? (
                        <Link href="/order-pad" data={filterQuery(filters)} className="inline-flex min-h-11 items-center text-muted-foreground hover:text-foreground md:min-h-0">
                            Back to first page
                        </Link>
                    ) : (
                        <span />
                    )}
                    {next_cursor !== null && (
                        <Button asChild variant="outline" size="sm" className="h-11 md:h-8">
                            <Link href="/order-pad" data={{ ...filterQuery(filters), after: next_cursor }} preserveState={false}>
                                Next {page_size} <ChevronRight />
                            </Link>
                        </Button>
                    )}
                </nav>
            </div>

            <StickyFooter context={totals_context} mode={display_mode} />
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

function Kbd({ children }: { children: ReactNode }) {
    return <kbd className="mx-0.5 rounded border bg-muted px-1 font-mono text-[10px]">{children}</kbd>;
}

/** The active filters in words, for the empty state (05.1 §10). */
function describeFilters(filters: PadFilters, facets: PadFacets): string[] {
    const parts: string[] = [];
    if (filters.q) parts.push(`“${filters.q}”`);
    if (filters.category) parts.push(`in ${facets.categories.find((c) => c.slug === filters.category)?.name ?? filters.category}`);
    if (filters.brand) parts.push(`by ${facets.brands.find((b) => b.slug === filters.brand)?.name ?? filters.brand}`);
    if (filters.in_stock) parts.push('in stock only');

    return parts;
}

function EmptyState({ onLaterPage, filters, facets }: { onLaterPage: boolean; filters: PadFilters; facets: PadFacets }) {
    const filtered = hasActiveFilters(filters);

    return (
        <div className="flex flex-col items-center rounded-md border border-dashed px-6 py-16 text-center">
            <PackageSearch className="mb-3 size-8 text-muted-foreground" aria-hidden />
            {onLaterPage ? (
                <>
                    <h2 className="font-medium">You've reached the end of the list</h2>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">There are no more products after the last page you viewed.</p>
                    <Button asChild variant="outline" size="sm" className="mt-4 h-11 md:h-8">
                        <Link href="/order-pad" data={filterQuery(filters)}>
                            Back to the first page
                        </Link>
                    </Button>
                </>
            ) : filtered ? (
                <>
                    <h2 className="font-medium">No products match {describeFilters(filters, facets).join(', ')}</h2>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">Try a shorter search or fewer filters. Quantities you've typed are kept.</p>
                    <Button
                        variant="outline"
                        size="sm"
                        className="mt-4 h-11 md:h-8"
                        onClick={() => visitWithFilters({ q: null, category: null, brand: null, in_stock: false })}
                    >
                        <X /> Clear filters
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
