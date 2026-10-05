/**
 * 05.2 §18.3 — the approval queue, for the company's owners and
 * approvers. Pending and decided tabs; search by order number; buyer and
 * date filters; sort; personal saved views; keyset "Load more"; cards on
 * mobile. Each row's one action opens the request — nothing is approved
 * from the list. Bulk rejection only, of selected loaded rows, with one
 * reason, each validated by the server and reported per record.
 */
import { Link, router, usePage } from '@inertiajs/react';
import { Bookmark, Keyboard } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';

import { ConfirmDialog, DANGER_BUTTON } from '@/components/trade/ConfirmDialog';
import { DataTable, type Column } from '@/components/trade/DataTable';
import { FilterBar, FilterDate, FilterSelect, type FilterChip } from '@/components/trade/FilterBar';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { ShortcutHelp } from '@/components/trade/ShortcutHelp';
import { EmptyState, ErrorState, FilteredEmptyState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { useListShortcuts } from '@/components/trade/useListShortcuts';
import { useSavedViews } from '@/components/trade/useSavedViews';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ApiError } from '@/lib/api/client';
import { bulkRejectApprovals } from '@/lib/api/credit';
import { formatUkDate, formatUkDateTime } from '@/lib/dateTime';
import { sumInts } from '@/lib/money';
import { APPROVAL_KIND, APPROVAL_STATUS, hoursUntil, type ApprovalRow } from '@/lib/trade/approvals';
import { cn } from '@/lib/utils';
import { toast } from '@/stores/toastStore';
import type { SharedProps } from '@/types/shared';

interface Filters {
    status: 'pending' | 'decided';
    buyer: string | null;
    from: string | null;
    to: string | null;
    q: string | null;
    sort: 'requested_desc' | 'expires_asc' | 'gross_desc';
}

interface Props {
    company: { name: string; account_code: string };
    filters: Filters;
    buyers: { id: string; name: string }[];
    pending_count: number;
    rows: ApprovalRow[];
    next_cursor: string | null;
}

const SORTS = [
    { value: 'requested_desc', label: 'Newest first' },
    { value: 'expires_asc', label: 'Expiring soonest' },
    { value: 'gross_desc', label: 'Largest total' },
];

/** Sortable headers map onto the server's three keyset sorts. */
const HEADER_SORT: Record<string, Filters['sort']> = { requested: 'requested_desc', expires: 'expires_asc', gross: 'gross_desc' };

/** A short, non-reversible tag so saved views are per user without storing who they are. */
function scopeTag(value: string): string {
    let hash = 0x811c9dc5;
    for (let i = 0; i < value.length; i++) {
        hash = Math.imul(hash ^ value.charCodeAt(i), 0x01000193) >>> 0;
    }

    return hash.toString(36);
}

export default function ApprovalsIndex({ company, filters, buyers, pending_count, rows, next_cursor }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const tz = useDisplayTimezone();
    const list = useKeysetList({ ...filters }, next_cursor);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [results, setResults] = useState<{ ok: number; failed: { label: string; message: string }[] } | null>(null);
    const idempotency = useRef<string | null>(null);

    const savedViews = useSavedViews(`${scopeTag(auth?.user.email ?? '')}|${auth?.company?.id ?? ''}|approvals`, ['status', 'from', 'to', 'sort']);
    const [viewName, setViewName] = useState('');

    const openRow = useCallback((index: number) => {
        const row = rows[index];
        if (row) {
            router.visit(`/trade/approvals/${row.id}`);
        }
    }, [rows]);
    const shortcuts = useListShortcuts({ rowCount: rows.length, onOpen: openRow });

    const hasFilters = filters.buyer !== null || filters.from !== null || filters.to !== null || filters.q !== null;
    const buyerName = buyers.find((b) => b.id === filters.buyer)?.name;
    const chips: FilterChip[] = [
        ...(filters.q ? [{ label: `Order: ${filters.q}`, onRemove: () => list.setFilters({ q: null }) }] : []),
        ...(filters.buyer ? [{ label: `Buyer: ${buyerName ?? 'selected'}`, onRemove: () => list.setFilters({ buyer: null }) }] : []),
        ...(filters.from ? [{ label: `From ${formatUkDate(`${filters.from}T12:00:00Z`, tz)}`, onRemove: () => list.setFilters({ from: null }) }] : []),
        ...(filters.to ? [{ label: `To ${formatUkDate(`${filters.to}T12:00:00Z`, tz)}`, onRemove: () => list.setFilters({ to: null }) }] : []),
    ];
    const clearFilters = () => list.setFilters({ buyer: null, from: null, to: null, q: null });

    const columns = useMemo<Column<ApprovalRow>[]>(() => {
        const decided = filters.status === 'decided';

        return [
            {
                id: 'order',
                header: 'Order',
                inCardTitle: true,
                cell: (r) => (
                    <div className="flex flex-col gap-1">
                        <span className="font-medium">{r.order_number}</span>
                        <span className="text-xs text-muted-foreground">{APPROVAL_KIND[r.kind]}</span>
                    </div>
                ),
            },
            { id: 'buyer', header: 'Buyer', cell: (r) => r.buyer_name },
            { id: 'gross', header: 'Total inc VAT', align: 'end', sortKey: 'gross', cell: (r) => <Money minor={r.gross_minor} /> },
            { id: 'requested', header: 'Requested', sortKey: 'requested', priority: 'secondary', cell: (r) => <span className="tabular-nums">{formatUkDateTime(r.requested_at, tz)}</span> },
            decided
                ? {
                      id: 'decided',
                      header: 'Decided',
                      cell: (r) => (
                          <span className="tabular-nums">
                              {formatUkDateTime(r.decided_at, tz)}
                              {r.decided_by && <span className="block text-xs text-muted-foreground">by {r.decided_by}</span>}
                          </span>
                      ),
                  }
                : {
                      id: 'expires',
                      header: 'Expires (UK)',
                      sortKey: 'expires',
                      cell: (r) => {
                          const left = hoursUntil(r.expires_at);

                          return (
                              <span className={cn('tabular-nums', left < 6 && 'font-semibold text-red-800')}>
                                  {formatUkDateTime(r.expires_at, tz, true)}
                                  <span className="block text-xs font-normal text-muted-foreground">{left < 1 ? 'Less than an hour left' : `${left} hours left`}</span>
                              </span>
                          );
                      },
                  },
            { id: 'status', header: 'Status', inCardTitle: true, cell: (r) => <StatusBadge tone={APPROVAL_STATUS[r.status].tone}>{r.kind === 'credit_exception' && r.status === 'pending' ? 'With accounts' : APPROVAL_STATUS[r.status].label}</StatusBadge> },
        ];
    }, [filters.status, tz]);

    const sortState = { key: Object.keys(HEADER_SORT).find((k) => HEADER_SORT[k] === filters.sort) ?? 'requested', direction: filters.sort === 'expires_asc' ? ('asc' as const) : ('desc' as const) };

    const submitBulkReject = async () => {
        if (reason.trim() === '') {
            setFailure('Give a reason. The buyers are shown it.');

            return;
        }
        idempotency.current ??= crypto.randomUUID();
        setPending(true);
        setFailure(null);
        try {
            const response = await bulkRejectApprovals([...selected], reason.trim(), idempotency.current);
            const failed = response.data.filter((r) => !r.ok).map((r) => ({ label: rows.find((row) => row.id === r.id)?.order_number ?? r.id, message: r.message ?? 'Not rejected.' }));
            const ok = response.data.length - failed.length;
            setResults({ ok, failed });
            if (ok > 0) {
                toast.success(`${ok} order${ok === 1 ? '' : 's'} rejected`, 'The buyers have been told, and stock and credit released.');
            }
            setRejecting(false);
            setReason('');
            setSelected(new Set());
            idempotency.current = null;
            router.reload({ only: ['rows', 'next_cursor', 'pending_count'] });
        } catch (error) {
            setFailure((error as ApiError).message);
        } finally {
            setPending(false);
        }
    };

    const firstUse = rows.length === 0 && !hasFilters;
    const decidable = rows.filter((r) => r.can_decide);

    return (
        <TradeShell title="Approvals">
            <PageHeader
                breadcrumbs={[{ label: 'Ordering', href: '/order-pad' }, { label: 'Approvals' }]}
                title="Approvals"
                description={<p>Orders above a buyer&apos;s limit wait here for up to 48 hours with their stock and credit held. Open a request to approve or reject it.</p>}
                secondaryActions={[{ label: 'Keyboard shortcuts', onSelect: () => shortcuts.setHelpOpen(true) }]}
            />

            <nav aria-label="Approval status" className="mb-4 flex gap-2 border-b">
                {(['pending', 'decided'] as const).map((status) => (
                    <button
                        key={status}
                        type="button"
                        aria-current={filters.status === status ? 'page' : undefined}
                        onClick={() => list.setFilters({ status })}
                        className={cn('-mb-px inline-flex min-h-11 items-center gap-2 border-b-2 px-3 text-sm font-medium', filters.status === status ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                    >
                        {status === 'pending' ? 'Waiting' : 'Decided'}
                        {status === 'pending' && <span className="rounded-full bg-muted px-2 text-xs tabular-nums">{pending_count}</span>}
                    </button>
                ))}
            </nav>

            <FilterBar
                search={{ value: filters.q ?? '', onChange: (q) => list.setFilters({ q }), label: 'Search by order number', placeholder: 'e.g. SO-1042' }}
                chips={chips}
                onClear={clearFilters}
                savedViews={
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" className="h-11">
                                <Bookmark aria-hidden /> Saved views
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-72">
                            <DropdownMenuLabel>Your saved views on this browser</DropdownMenuLabel>
                            {savedViews.views.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">None yet.</p>}
                            {savedViews.views.map((view) => (
                                <DropdownMenuItem key={view.name} className="min-h-11" onSelect={() => list.setFilters({ status: 'pending', from: null, to: null, sort: 'requested_desc', ...view.values })}>
                                    {view.name}
                                </DropdownMenuItem>
                            ))}
                            <DropdownMenuSeparator />
                            <form
                                className="flex gap-2 p-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    if (viewName.trim() !== '') {
                                        savedViews.save(viewName.trim(), { status: filters.status, from: filters.from, to: filters.to, sort: filters.sort });
                                        setViewName('');
                                        toast.success('View saved', 'Status, dates and sort only — on this browser.');
                                    }
                                }}
                                onKeyDown={(e) => e.stopPropagation()}
                            >
                                <label className="sr-only" htmlFor="view-name">
                                    Name this view
                                </label>
                                <input id="view-name" value={viewName} onChange={(e) => setViewName(e.target.value)} placeholder="Name this view" className="h-11 min-w-0 flex-1 rounded-md border px-2 text-sm" maxLength={40} />
                                <Button type="submit" className="h-11">
                                    Save
                                </Button>
                            </form>
                        </DropdownMenuContent>
                    </DropdownMenu>
                }
            >
                <FilterSelect label="Buyer" value={filters.buyer ?? ''} onChange={(buyer) => list.setFilters({ buyer: buyer || null })} options={[{ value: '', label: 'All buyers' }, ...buyers.map((b) => ({ value: b.id, label: b.name }))]} />
                <FilterDate label="Requested from" value={filters.from ?? ''} onChange={(from) => list.setFilters({ from: from || null })} />
                <FilterDate label="Requested to" value={filters.to ?? ''} onChange={(to) => list.setFilters({ to: to || null })} />
                <FilterSelect label="Sort" value={filters.sort} onChange={(sort) => list.setFilters({ sort })} options={SORTS} />
            </FilterBar>

            {results && results.failed.length > 0 && (
                <div role="alert" className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <p className="font-semibold">
                        {results.ok} rejected; {results.failed.length} could not be rejected:
                    </p>
                    <ul className="mt-2 list-disc space-y-1 pl-5">
                        {results.failed.map((f) => (
                            <li key={f.label}>
                                {f.label}: {f.message}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {list.failure ? (
                <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
            ) : list.refreshing ? (
                <TableSkeleton label="Loading approvals" />
            ) : rows.length === 0 ? (
                firstUse ? (
                    filters.status === 'pending' ? (
                        <EmptyState title="Nothing is waiting for approval" action={<Button asChild variant="outline" className="h-11"><Link href="/order-pad">Go to the order pad</Link></Button>}>
                            When a buyer orders above their limit, the order appears here and you are emailed.
                        </EmptyState>
                    ) : (
                        <EmptyState title="No decisions yet">Approved, rejected and expired requests appear here.</EmptyState>
                    )
                ) : (
                    <FilteredEmptyState onClear={clearFilters} />
                )
            ) : (
                <DataTable
                    caption={`${filters.status === 'pending' ? 'Waiting' : 'Decided'} approval requests for ${company.name}`}
                    columns={columns}
                    rows={rows}
                    rowKey={(r) => r.id}
                    rowLabel={(r) => r.order_number}
                    cardTitle={(r) => (
                        <span className="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {r.order_number}
                                <span className="block text-xs font-normal text-muted-foreground">{APPROVAL_KIND[r.kind]}</span>
                            </span>
                            <StatusBadge tone={APPROVAL_STATUS[r.status].tone}>{r.kind === 'credit_exception' && r.status === 'pending' ? 'With accounts' : APPROVAL_STATUS[r.status].label}</StatusBadge>
                        </span>
                    )}
                    rowAction={(r) => (
                        <Button asChild variant={r.can_decide ? 'default' : 'outline'} className="h-11">
                            <Link href={`/trade/approvals/${r.id}`}>Open request<span className="sr-only"> for {r.order_number}</span></Link>
                        </Button>
                    )}
                    sort={sortState}
                    onSort={(key) => list.setFilters({ sort: HEADER_SORT[key] })}
                    selection={
                        filters.status === 'pending' && decidable.length > 0
                            ? {
                                  selected,
                                  onChange: setSelected,
                                  isSelectable: (r) => r.can_decide,
                                  actions: (
                                      <Button className={cn('h-11', DANGER_BUTTON)} onClick={() => setRejecting(true)}>
                                          Reject selected…
                                      </Button>
                                  ),
                              }
                            : undefined
                    }
                    loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more requests' }}
                    activeIndex={shortcuts.active}
                />
            )}

            <p className="mt-6 flex items-center gap-2 text-xs text-muted-foreground">
                <Keyboard className="size-4" aria-hidden /> Press <kbd className="rounded border bg-muted px-1">?</kbd> for keyboard shortcuts.
            </p>

            <ShortcutHelp open={shortcuts.helpOpen} onOpenChange={shortcuts.setHelpOpen} disabled={shortcuts.disabled} onDisabledChange={shortcuts.setDisabled} />

            <ConfirmDialog
                open={rejecting}
                onOpenChange={(open) => {
                    setRejecting(open);
                    setFailure(null);
                }}
                title={`Reject ${selected.size} order${selected.size === 1 ? '' : 's'}?`}
                facts={[
                    { label: 'Company', value: company.name },
                    { label: 'Orders', value: rows.filter((r) => selected.has(r.id)).map((r) => r.order_number).join(', ') },
                    { label: 'Total inc VAT', value: <Money minor={sumInts(rows.filter((r) => selected.has(r.id)).map((r) => r.gross_minor))} /> },
                ]}
                consequences="Each order is cancelled, its stock and credit released, and its buyer emailed the reason. Each one is checked separately; any that cannot be rejected are listed afterwards."
                confirmLabel="Reject orders"
                destructive
                pending={pending}
                error={failure}
                onConfirm={submitBulkReject}
                confirmDisabled={reason.trim() === ''}
            >
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="bulk-reason">Reason (shown to the buyers)</Label>
                    <Textarea id="bulk-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} required aria-required className="min-h-24" />
                </div>
            </ConfirmDialog>
        </TradeShell>
    );
}
