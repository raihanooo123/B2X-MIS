/**
 * 05.1 §7, §14.2 — import reconciliation: what matched, what needs a
 * different quantity, what could not be found or ordered, and what was
 * merged — before anything enters the basket. The buyer selects rows; a
 * suggested quantity is used only where they accept it. Prices are
 * indicative (the live resolver) until checkout. "Add selected" merges
 * into the basket once; a changed basket, price, stock or pack refreshes
 * this preview instead. A large import is checked in the background: the
 * page polls while it is visible, backing off, and stops when done.
 */
import { Link, router } from '@inertiajs/react';
import { CheckCircle2, Download, Loader2, ShoppingCart, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, SuccessState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge, type StatusTone } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api/client';
import { useBulkResolve } from '@/lib/api/orderPad';
import { confirmImport, importStatus, SELECTABLE, type ImportDto, type ImportOutcome, type ImportRow } from '@/lib/api/orderTools';
import { sumInts } from '@/lib/money';
import { linePrice, pricingFromEntry } from '@/lib/pricing/localRecompute';
import { cn } from '@/lib/utils';

const OUTCOME: Record<ImportOutcome, { label: string; tone: StatusTone }> = {
    ok: { label: 'Ready', tone: 'success' },
    adjust: { label: 'Check quantity', tone: 'warning' },
    short: { label: 'Low stock', tone: 'warning' },
    not_found: { label: 'Not found', tone: 'danger' },
    ambiguous: { label: 'Unclear code', tone: 'danger' },
    inactive: { label: 'Not available', tone: 'neutral' },
    no_pack: { label: 'Pack not sold', tone: 'danger' },
    invalid: { label: 'Cannot read', tone: 'danger' },
};

const SOURCE: Record<ImportDto['source'], string> = { paste: 'Pasted list', csv: 'CSV file', saved_list: 'Saved list', reorder: 'Reorder' };

type Filter = 'all' | 'ready' | 'attention';

export default function OrderToolsReconcile({ import: imp, cart_version }: { import: ImportDto; cart_version: string }) {
    const [filter, setFilter] = useState<Filter>('all');
    const [selected, setSelected] = useState<Set<number>>(() => new Set(imp.rows.filter((r) => r.outcome === 'ok' || r.outcome === 'short').map((r) => r.row_no)));
    const [accepted, setAccepted] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Re-seed the selection when the server sends a refreshed preview.
    useEffect(() => {
        setSelected(new Set(imp.rows.filter((r) => r.outcome === 'ok' || r.outcome === 'short').map((r) => r.row_no)));
        setAccepted(new Set());
    }, [imp.version, imp.rows]);

    const working = imp.status === 'pending' || imp.status === 'processing';
    usePollWhileWorking(imp.id, working);

    const skuIds = useMemo(() => [...new Set(imp.rows.filter((r) => SELECTABLE.includes(r.outcome) && r.sku_id).map((r) => r.sku_id as string))], [imp.rows]);
    const prices = useBulkResolve(skuIds, { enabled: imp.status === 'ready' });
    const pricing = useMemo(() => new Map((prices.data?.data ?? []).map((e) => [e.sku_id, pricingFromEntry(e)])), [prices.data]);

    const qtyOf = (r: ImportRow): number => (r.outcome === 'adjust' ? (r.suggested_pack_qty ?? 0) : (r.pack_qty ?? 0));
    const lineNet = (r: ImportRow): number | null => {
        const p = r.sku_id ? pricing.get(r.sku_id) : null;
        if (!p || r.pack_base_units === null) {
            return null;
        }
        return linePrice(p, qtyOf(r) * r.pack_base_units)?.itemNetMinor ?? null;
    };

    const chosen = imp.rows.filter((r) => selected.has(r.row_no) && (r.outcome !== 'adjust' || accepted.has(r.row_no)));
    const total = sumInts(chosen.map((r) => lineNet(r) ?? 0));
    const attention = imp.rows.filter((r) => r.outcome !== 'ok');
    const visible = filter === 'all' ? imp.rows : filter === 'ready' ? imp.rows.filter((r) => r.outcome === 'ok') : attention;

    const toggle = (no: number) =>
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(no) ? next.delete(no) : next.add(no);
            return next;
        });
    const toggleAccept = (no: number) => {
        setAccepted((prev) => {
            const next = new Set(prev);
            next.has(no) ? next.delete(no) : next.add(no);
            return next;
        });
        setSelected((prev) => new Set(prev).add(no));
    };

    const confirm = async () => {
        setBusy(true);
        setError(null);
        try {
            await confirmImport(imp.id, { version: imp.version, rows: chosen.map((r) => r.row_no), accept: [...accepted], cart_version });
            router.reload({ only: ['import', 'cart_version'] });
        } catch (e) {
            if (e instanceof ApiError && e.status === 409) {
                setError(e.message);
                router.reload({ only: ['import', 'cart_version'] });
            } else {
                setError(e instanceof ApiError ? e.message : 'The connection failed. Nothing was added. Try again.');
            }
        } finally {
            setBusy(false);
        }
    };

    const header = (
        <PageHeader
            breadcrumbs={[{ label: 'Order pad', href: '/order-pad' }, { label: 'Check and add' }]}
            title="Check before adding"
            description={<p>{SOURCE[imp.source]} · {imp.row_count.toLocaleString('en-GB')} lines. Prices are a guide until checkout.</p>}
            primaryAction={attention.some((r) => !SELECTABLE.includes(r.outcome)) ? (
                <Button asChild variant="outline" className="h-11">
                    <a href={`/trade/order-tools/imports/${imp.id}/problems.csv`}>
                        <Download aria-hidden /> Download problems
                    </a>
                </Button>
            ) : undefined}
        />
    );

    if (working) {
        return (
            <TradeShell title="Checking your list">
                {header}
                <p role="status" className="mb-4 flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" aria-hidden /> Checking {imp.row_count.toLocaleString('en-GB')} lines. This page updates when it is done.
                </p>
                <TableSkeleton label="Checking lines" columns={6} />
            </TradeShell>
        );
    }

    if (imp.status === 'confirmed' && imp.confirmed) {
        return (
            <TradeShell title="Added to cart">
                {header}
                <SuccessState title={`${imp.confirmed.lines} line${imp.confirmed.lines === 1 ? '' : 's'} added to your cart`} action={<><Button asChild className="h-11"><Link href="/cart"><ShoppingCart aria-hidden /> Go to cart</Link></Button><Button asChild variant="outline" className="h-11"><Link href="/order-pad">Back to the order pad</Link></Button></>}>
                    They were added to anything already in your cart.
                </SuccessState>
            </TradeShell>
        );
    }

    if (imp.status === 'expired' || imp.status === 'failed') {
        return (
            <TradeShell title="Import">
                {header}
                <ErrorState
                    title={imp.status === 'expired' ? 'This import has expired' : 'This import could not be checked'}
                    message={imp.status === 'expired' ? 'Imports are kept for 24 hours. Nothing was added. Paste or upload it again.' : 'Nothing was added. Try importing it again.'}
                />
                <Button asChild className="mt-4 h-11"><Link href="/trade/order-tools/import">Paste or upload again</Link></Button>
            </TradeShell>
        );
    }

    return (
        <TradeShell title="Check before adding">
            {header}
            <div className="pb-36">
                <ul className="mb-4 flex flex-wrap gap-2" aria-label="Summary">
                    {(Object.keys(OUTCOME) as ImportOutcome[]).filter((o) => (imp.counts[o] ?? 0) > 0).map((o) => (
                        <li key={o}><StatusBadge tone={OUTCOME[o].tone}>{`${OUTCOME[o].label}: ${imp.counts[o]}`}</StatusBadge></li>
                    ))}
                </ul>

                <nav aria-label="Show" className="mb-4 flex gap-2 border-b">
                    {([['all', 'All lines'], ['ready', 'Ready'], ['attention', 'Needs attention']] as const).map(([value, label]) => (
                        <button key={value} type="button" aria-current={filter === value ? 'page' : undefined} onClick={() => setFilter(value)}
                            className={cn('-mb-px inline-flex min-h-11 items-center border-b-2 px-3 text-sm font-medium', filter === value ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}>
                            {label}{value === 'attention' && attention.length > 0 && <span className="ml-2 rounded-full bg-amber-100 px-2 text-xs text-amber-900">{attention.length}</span>}
                        </button>
                    ))}
                </nav>

                {imp.rows.length === 0 ? (
                    <EmptyState title="Nothing to check">The list had no lines to read.</EmptyState>
                ) : (
                    <ul className="flex flex-col gap-2" aria-label="Lines">
                        {visible.map((r) => {
                            const selectable = SELECTABLE.includes(r.outcome);
                            const net = lineNet(r);
                            return (
                                <li key={r.row_no} className={cn('flex flex-wrap items-start gap-3 rounded-xl border bg-background p-4', !selectable && 'bg-muted/30')}>
                                    <input
                                        type="checkbox"
                                        className="mt-1 size-5"
                                        disabled={!selectable || (r.outcome === 'adjust' && !accepted.has(r.row_no))}
                                        checked={selectable && selected.has(r.row_no) && (r.outcome !== 'adjust' || accepted.has(r.row_no))}
                                        onChange={() => toggle(r.row_no)}
                                        aria-label={`Add line ${r.row_no}${r.sku_code ? `, ${r.sku_code}` : ''}`}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-xs tabular-nums text-muted-foreground">Line {r.row_no}</span>
                                            <StatusBadge tone={OUTCOME[r.outcome].tone}>{OUTCOME[r.outcome].label}</StatusBadge>
                                            {r.merged_row_nos.length > 0 && <span className="text-xs text-muted-foreground">Merged with line{r.merged_row_nos.length === 1 ? '' : 's'} {r.merged_row_nos.join(', ')}</span>}
                                        </div>
                                        <p className="mt-1 font-medium">{r.name ?? r.input}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {r.sku_code && <span className="font-mono">{r.sku_code}</span>}
                                            {r.pack_label && <> · {r.pack_label}</>}
                                            {r.name && <> · you entered <span className="font-mono">{r.input}</span></>}
                                        </p>
                                        {r.problem && (
                                            <p className={cn('mt-2 flex gap-1.5 text-sm', selectable ? 'text-amber-900' : 'text-red-900')}>
                                                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> {r.problem}
                                            </p>
                                        )}
                                        {r.suggestions.length > 0 && <p className="mt-1 text-sm text-muted-foreground">Did you mean: <span className="font-mono">{r.suggestions.join(', ')}</span>?</p>}
                                        {r.outcome === 'adjust' && (
                                            <label className="mt-2 inline-flex min-h-11 items-center gap-2 text-sm">
                                                <input type="checkbox" className="size-5" checked={accepted.has(r.row_no)} onChange={() => toggleAccept(r.row_no)} />
                                                Use {r.suggested_pack_qty} packs instead of {r.pack_qty}
                                            </label>
                                        )}
                                    </div>
                                    {selectable && (
                                        <div className="ml-auto text-right text-sm">
                                            <p className="font-medium tabular-nums">{qtyOf(r)} × {r.pack_label}</p>
                                            <p className="text-xs tabular-nums text-muted-foreground">{((r.pack_base_units ?? 0) * qtyOf(r)).toLocaleString('en-GB')} units</p>
                                            <p className="mt-1">{net === null ? <span className="text-xs text-muted-foreground">{prices.isPending ? 'Pricing…' : 'Price at checkout'}</span> : <Money minor={net} />}</p>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <footer className="fixed inset-x-0 bottom-0 z-20 border-t bg-background/95 backdrop-blur xl:left-60">
                <div className="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-3 px-4 py-3 md:px-6">
                    <div className="text-sm">
                        <p><span className="font-semibold tabular-nums">{chosen.length}</span> of {imp.rows.filter((r) => SELECTABLE.includes(r.outcome)).length} lines selected · guide price <Money minor={total} className="font-semibold" /> ex. VAT</p>
                        {error && <p role="alert" className="mt-1 text-red-900">{error}</p>}
                    </div>
                    <Button className="h-11" disabled={chosen.length === 0 || busy} onClick={confirm}>
                        {busy ? <Loader2 className="animate-spin" aria-hidden /> : <CheckCircle2 aria-hidden />} Add selected to cart
                    </Button>
                </div>
            </footer>
        </TradeShell>
    );
}

/** Poll while the server is still checking, backing off on failure; stop when done or hidden. */
function usePollWhileWorking(id: string, working: boolean) {
    useEffect(() => {
        if (!working) {
            return;
        }
        let delay = 1500;
        let timer: number | undefined;
        let stopped = false;
        const tick = async () => {
            if (document.visibilityState === 'visible') {
                try {
                    const result = await importStatus(id);
                    if (result.data.status !== 'pending' && result.data.status !== 'processing') {
                        router.reload({ only: ['import'] });
                        return;
                    }
                    delay = 1500;
                } catch {
                    delay = Math.min(delay * 2, 30000);
                }
            }
            if (!stopped) {
                timer = window.setTimeout(tick, delay);
            }
        };
        timer = window.setTimeout(tick, delay);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [id, working]);
}
