/**
 * 05.17 §4 — company order history, for every member: search by order
 * number or your reference, status and date filters, date sort, keyset
 * "Load more" (filters reset the list to its first page), cards on
 * mobile. Orders not yet placed are listed apart, never in history.
 */
import { Link } from '@inertiajs/react';
import { Package, ShoppingCart } from 'lucide-react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { FilterBar, FilterDate, FilterSelect, type FilterChip } from '@/components/trade/FilterBar';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, FilteredEmptyState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { formatUkDate } from '@/lib/dateTime';
import { DATE_SORTS, ORDER_STATUS_FILTERS, dayIso, orderTone, type OrderRow } from '@/lib/trade/selfService';

interface Filters {
    status: string | null;
    q: string | null;
    from: string | null;
    to: string | null;
    sort: string;
}

interface Props {
    company: { name: string; account_code: string };
    filters: Filters;
    drafts: OrderRow[];
    rows: OrderRow[];
    next_cursor: string | null;
}

export default function OrdersIndex({ company, filters, drafts, rows, next_cursor }: Props) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({ ...filters }, next_cursor);

    const statusLabel = ORDER_STATUS_FILTERS.find((s) => s.value === filters.status)?.label;
    const hasFilters = filters.status !== null || filters.q !== null || filters.from !== null || filters.to !== null;
    const chips: FilterChip[] = [
        ...(filters.q ? [{ label: `Search: ${filters.q}`, onRemove: () => list.setFilters({ q: null }) }] : []),
        ...(filters.status ? [{ label: `Status: ${statusLabel ?? filters.status}`, onRemove: () => list.setFilters({ status: null }) }] : []),
        ...(filters.from ? [{ label: `From ${formatUkDate(dayIso(filters.from), tz)}`, onRemove: () => list.setFilters({ from: null }) }] : []),
        ...(filters.to ? [{ label: `To ${formatUkDate(dayIso(filters.to), tz)}`, onRemove: () => list.setFilters({ to: null }) }] : []),
    ];
    const clearFilters = () => list.setFilters({ status: null, q: null, from: null, to: null });

    const columns: Column<OrderRow>[] = [
        {
            id: 'order',
            header: 'Order',
            inCardTitle: true,
            cell: (r) => (
                <Link href={`/trade/orders/${r.id}`} className="font-medium underline-offset-4 hover:underline">
                    {r.order_number}
                </Link>
            ),
        },
        { id: 'reference', header: 'Your reference', cell: (r) => r.customer_reference ?? '—' },
        { id: 'placed', header: 'Placed', sortKey: 'placed', cell: (r) => <span className="tabular-nums">{formatUkDate(r.placed_at, tz)}</span> },
        { id: 'by', header: 'Placed by', priority: 'secondary', cell: (r) => r.placed_by ?? '—' },
        { id: 'status', header: 'Status', cell: (r) => <StatusBadge tone={orderTone(r.status)}>{r.status_label}</StatusBadge> },
        { id: 'total', header: 'Total inc VAT', align: 'end', cell: (r) => <Money minor={r.total_gross_minor} /> },
    ];

    return (
        <TradeShell title="Orders">
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Orders' }]}
                title="Orders"
                description={<p>Every order placed for {company.name}, by anyone on your team.</p>}
                primaryAction={
                    <Button asChild className="h-11">
                        <Link href="/order-pad">
                            <ShoppingCart aria-hidden /> Start order
                        </Link>
                    </Button>
                }
            />

            {drafts.length > 0 && (
                <section aria-labelledby="drafts-heading" className="mb-6 rounded-xl border bg-background p-4">
                    <h2 id="drafts-heading" className="mb-2 text-base font-semibold">
                        Not yet placed
                    </h2>
                    <ul className="divide-y">
                        {drafts.map((d) => (
                            <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span>
                                    <span className="font-medium">{d.order_number}</span>
                                    {d.placed_by && <span className="text-muted-foreground"> · {d.placed_by}</span>}
                                </span>
                                <StatusBadge tone="neutral">{d.status_label}</StatusBadge>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <FilterBar search={{ value: filters.q ?? '', onChange: (q) => list.setFilters({ q }), label: 'Search by order number or your reference', placeholder: 'e.g. SO-1042 or PO-778' }} chips={chips} onClear={clearFilters}>
                <FilterSelect label="Status" value={filters.status ?? ''} onChange={(status) => list.setFilters({ status: status || null })} options={ORDER_STATUS_FILTERS} />
                <FilterDate label="Placed from" value={filters.from ?? ''} onChange={(from) => list.setFilters({ from: from || null })} />
                <FilterDate label="Placed to" value={filters.to ?? ''} onChange={(to) => list.setFilters({ to: to || null })} />
                <FilterSelect label="Sort" value={filters.sort} onChange={(sort) => list.setFilters({ sort })} options={DATE_SORTS.orders} />
            </FilterBar>

            {list.failure ? (
                <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
            ) : list.refreshing ? (
                <TableSkeleton label="Loading orders" columns={6} />
            ) : rows.length === 0 ? (
                hasFilters ? (
                    <FilteredEmptyState onClear={clearFilters} />
                ) : (
                    <EmptyState icon={Package} title="No orders yet" action={<Button asChild className="h-11"><Link href="/order-pad">Start an order</Link></Button>}>
                        Orders placed for {company.name} appear here with their status and totals.
                    </EmptyState>
                )
            ) : (
                <DataTable
                    caption={`Orders for ${company.name}, ${filters.sort === 'placed_asc' ? 'oldest' : 'newest'} first`}
                    columns={columns}
                    rows={rows}
                    rowKey={(r) => r.id}
                    rowLabel={(r) => r.order_number}
                    cardTitle={(r) => r.order_number}
                    sort={{ key: 'placed', direction: filters.sort === 'placed_asc' ? 'asc' : 'desc' }}
                    onSort={() => list.setFilters({ sort: filters.sort === 'placed_asc' ? 'placed_desc' : 'placed_asc' })}
                    rowAction={(r) => (
                        <Button asChild variant="outline" className="h-11">
                            <Link href={`/trade/orders/${r.id}`}>View order</Link>
                        </Button>
                    )}
                    loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more orders' }}
                />
            )}
        </TradeShell>
    );
}
