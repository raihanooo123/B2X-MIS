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
 * A trade user acting for a company sees the pad inside the 05.16 trade
 * shell (sidebar with Cart, company switch), table first with no page
 * header; a guest or public customer keeps the standalone layout.
 *
 * Barcode scanning (05.1 §8.2): after a scan (camera, or Enter from a
 * handheld scanner — PadToolbar), the reloaded page focuses the matched
 * row's quantity with the scanned case's pack preselected, and says so;
 * a code that matches nothing, or an item not on sale, is said plainly.
 */
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, ClipboardPaste, ListChecks, PackageSearch, RotateCcw, X } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { TradeShell } from '@/components/trade/TradeShell';
import { Button } from '@/components/ui/button';
import { TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useBulkResolve, useStockAvailability, type BulkResolveEntry, type StockAvailabilityEntry } from '@/lib/api/orderPad';
import { pricingFromEntry, type LinePricing } from '@/lib/pricing/localRecompute';
import { DESKTOP_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { vatLabel } from '@/lib/cart/display';
import { cn } from '@/lib/utils';
import { useOrderPadStore } from '@/stores/orderPadStore';
import type { SharedProps } from '@/types/shared';

import { PadCard } from './components/PadCard';
import { PadRow } from './components/PadRow';
import { filterQuery, hasActiveFilters, PadToolbar, visitWithFilters } from './components/PadToolbar';
import { StickyFooter } from './components/StickyFooter';
import { QTY_INPUT_ATTRIBUTE } from '@/lib/keyboard/tabOrder';

import type { OrderPadProps, PadFacets, PadFilters, PadRowData, ScanMatch } from './types';

export default function OrderPadIndex({ catalogue, filters, facets, page_size, totals_context, display_mode, scan }: OrderPadProps) {
    const { rows, start_row, next_cursor } = catalogue;
    const skuIds = useMemo(() => rows.map((r) => r.sku_id), [rows]);
    const navigating = useInertiaNavigating();
    const desktop = useMediaQuery(DESKTOP_QUERY);
    // Acting for a trade company: the pad lives inside the trade shell.
    const tradeNav = usePage<SharedProps>().props.auth?.trade_navigation ?? null;
    const trade = tradeNav !== null;

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

    const scanNotice = useScanFocus(filters, scan ?? null, rows);

    const lastRow = start_row + rows.length - 1;
    const rowData = (skuId: string) => ({
        mode: display_mode,
        price: priceBySku.get(skuId),
        priceLoading: prices.isPending || prices.isPlaceholderData,
        stock: stockBySku.get(skuId),
        stockLoading: stock.isPending || stock.isPlaceholderData,
    });

    const rowsLabel = rows.length > 0 ? `Rows ${start_row.toLocaleString('en-GB')}–${lastRow.toLocaleString('en-GB')}` : null;
    const pad = (
        <>
                <PadToolbar
                    filters={filters}
                    facets={facets}
                    actions={
                        tradeNav?.order_tools ? (
                            <>
                                <Button asChild variant="outline" className="h-11 md:h-9">
                                    <Link href="/trade/order-tools/import">
                                        <ClipboardPaste aria-hidden /> Paste or upload
                                    </Link>
                                </Button>
                                <Button asChild variant="outline" className="h-11 md:h-9">
                                    <Link href="/trade/saved-lists">
                                        <ListChecks aria-hidden /> Saved lists
                                    </Link>
                                </Button>
                            </>
                        ) : undefined
                    }
                />
                <p role="status" aria-live="polite" className={cn('mb-2 text-sm', scanNotice === null ? 'sr-only' : scanNotice.tone === 'ok' ? 'text-emerald-800' : 'rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900')}>
                    {scanNotice?.text}
                </p>
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
                        {/* A plain <table>, not <Table>: its overflow wrapper would trap the sticky header. */}
                        <table className="w-full caption-bottom text-sm">
                            <TableHeader className={cn('sticky z-10 bg-background', trade ? 'top-16' : 'top-0')}>
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
                        </table>
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
        </>
    );

    if (trade) {
        return (
            <TradeShell title="Order pad">
                <div className="pb-56 md:pb-36">
                    {/* Straight to the table: the sidebar already says where you are. */}
                    <h1 className="sr-only">Order pad</h1>
                    {pad}
                </div>
                <StickyFooter context={totals_context} mode={display_mode} withSidebar />
            </TradeShell>
        );
    }

    return (
        <>
            <Head title="Order pad" />

            <div className="mx-auto max-w-[1400px] px-4 pb-56 pt-4 md:pb-36">
                <header className="mb-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-2xl border bg-background px-5 py-4 shadow-sm">
                    <div className="flex items-baseline gap-4">
                        <h1 className="text-2xl font-semibold tracking-tight">Order pad</h1>
                        {rowsLabel && <p className="text-xs tabular-nums text-muted-foreground">{rowsLabel}</p>}
                    </div>
                    <AccountMenu />
                </header>

                {pad}
            </div>

            <StickyFooter context={totals_context} mode={display_mode} />
        </>
    );
}

/**
 * After a scan's results arrive: preselect the scanned pack, focus the
 * row's quantity, and describe the outcome. Acts once per scan, and only
 * for a scan (scanIntent), so typing in the search never moves focus. The
 * message belongs to the search it describes and goes when that changes.
 */
function useScanFocus(filters: PadFilters, scan: ScanMatch | null, rows: PadRowData[]): { tone: 'ok' | 'warn'; text: string } | null {
    const intent = useOrderPadStore((s) => s.scanIntent);
    const expectScan = useOrderPadStore((s) => s.expectScan);
    const setPack = useOrderPadStore((s) => s.setPack);
    const [notice, setNotice] = useState<{ query: string; tone: 'ok' | 'warn'; text: string } | null>(null);
    const query = filters.q;
    const narrowed = filters.category !== null || filters.brand !== null || filters.in_stock;

    useEffect(() => {
        if (intent === null || query !== intent) {
            return;
        }
        expectScan(null);
        const say = (tone: 'ok' | 'warn', text: string) => setNotice({ query: intent, tone, text });
        if (scan === null) {
            if (rows.length === 0) say('warn', `No product has the barcode ${intent}.`);
            return;
        }
        const row = rows.find((r) => r.sku_id === scan.sku_id);
        if (row === undefined) {
            say('warn', narrowed ? `The product with barcode ${intent} isn't in this filtered list. Clear the filters to see it.` : `The product with barcode ${intent} isn't available to order.`);
            return;
        }
        const pack = scan.pack_code === null ? undefined : row.packs.find((p) => p.code === scan.pack_code);
        if (pack !== undefined) {
            setPack(row, pack);
        }
        say('ok', `Found ${row.sku_code} — ${row.product_name}${pack ? `, ${pack.label}` : ''}. Type the quantity.`);
        window.requestAnimationFrame(() => {
            const input = document.querySelector<HTMLInputElement>(`[data-sku-row="${CSS.escape(row.sku_id)}"] [${QTY_INPUT_ATTRIBUTE}]`);
            input?.focus();
            input?.select();
            input?.scrollIntoView({ block: 'center' });
        });
    }, [intent, query, narrowed, scan, rows, expectScan, setPack]);

    return notice !== null && notice.query === query ? notice : null;
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
