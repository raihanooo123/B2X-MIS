/**
 * 05.17 §4 — the company's invoices, for owners and approvers: due and
 * overdue summary, status and date filters, search by invoice number,
 * date sort, keyset "Load more". Outstanding = total − paid − credited.
 */
import { Link } from '@inertiajs/react';
import { FileText, TriangleAlert } from 'lucide-react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { FilterBar, FilterDate, FilterSelect, type FilterChip } from '@/components/trade/FilterBar';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, FilteredEmptyState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { SummaryCard } from '@/components/trade/SummaryCard';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { formatUkDate } from '@/lib/dateTime';
import { DATE_SORTS, INVOICE_STATUS_FILTERS, dayIso, invoiceBadge, type InvoiceRow } from '@/lib/trade/selfService';

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
    summary: { outstanding_count: number; outstanding_minor: number; overdue_count: number; overdue_minor: number };
    rows: InvoiceRow[];
    next_cursor: string | null;
}

export default function InvoicesIndex({ company, filters, summary, rows, next_cursor }: Props) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({ ...filters }, next_cursor);

    const hasFilters = filters.status !== null || filters.q !== null || filters.from !== null || filters.to !== null;
    const chips: FilterChip[] = [
        ...(filters.q ? [{ label: `Search: ${filters.q}`, onRemove: () => list.setFilters({ q: null }) }] : []),
        ...(filters.status ? [{ label: `Status: ${INVOICE_STATUS_FILTERS.find((s) => s.value === filters.status)?.label ?? filters.status}`, onRemove: () => list.setFilters({ status: null }) }] : []),
        ...(filters.from ? [{ label: `From ${formatUkDate(dayIso(filters.from), tz)}`, onRemove: () => list.setFilters({ from: null }) }] : []),
        ...(filters.to ? [{ label: `To ${formatUkDate(dayIso(filters.to), tz)}`, onRemove: () => list.setFilters({ to: null }) }] : []),
    ];
    const clearFilters = () => list.setFilters({ status: null, q: null, from: null, to: null });

    const columns: Column<InvoiceRow>[] = [
        {
            id: 'invoice',
            header: 'Invoice',
            inCardTitle: true,
            cell: (r) => (
                <Link href={`/trade/invoices/${r.id}`} className="font-medium underline-offset-4 hover:underline">
                    {r.number}
                </Link>
            ),
        },
        { id: 'issued', header: 'Issued', sortKey: 'issued', cell: (r) => <span className="tabular-nums">{formatUkDate(r.issued_at, tz)}</span> },
        { id: 'due', header: 'Due', cell: (r) => <span className="tabular-nums">{r.due_at ? formatUkDate(r.due_at, tz) : 'On receipt'}</span> },
        { id: 'status', header: 'Status', cell: (r) => <StatusBadge tone={invoiceBadge(r).tone}>{invoiceBadge(r).label}</StatusBadge> },
        { id: 'total', header: 'Total', align: 'end', priority: 'secondary', cell: (r) => <Money minor={r.total_gross_minor} /> },
        { id: 'paid', header: 'Paid', align: 'end', priority: 'secondary', cell: (r) => <Money minor={r.paid_minor} /> },
        { id: 'credited', header: 'Credited', align: 'end', priority: 'secondary', cell: (r) => <Money minor={r.credited_minor} /> },
        { id: 'outstanding', header: 'Outstanding', align: 'end', cell: (r) => <Money minor={r.outstanding_minor} className="font-semibold" /> },
    ];

    return (
        <TradeShell title="Invoices">
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Invoices' }]}
                title="Invoices"
                description={<p>{company.name} · {company.account_code}. Each invoice keeps the details it was issued with.</p>}
                primaryAction={
                    <Button asChild variant="outline" className="h-11">
                        <Link href="/trade/statements">Statements</Link>
                    </Button>
                }
            />

            <dl className="mb-6 grid gap-4 sm:grid-cols-2">
                <SummaryCard label="Outstanding" value={<Money minor={summary.outstanding_minor} />} note={`${summary.outstanding_count} unpaid invoice${summary.outstanding_count === 1 ? '' : 's'}`} emphasis />
                <SummaryCard
                    label="Overdue"
                    value={<Money minor={summary.overdue_minor} />}
                    note={
                        summary.overdue_count > 0 ? (
                            <span className="inline-flex items-center gap-1 font-medium text-red-800">
                                <TriangleAlert className="size-3.5" aria-hidden /> {summary.overdue_count} overdue
                            </span>
                        ) : (
                            'Nothing overdue'
                        )
                    }
                />
            </dl>

            <FilterBar search={{ value: filters.q ?? '', onChange: (q) => list.setFilters({ q }), label: 'Search by invoice number', placeholder: 'e.g. INV-001042' }} chips={chips} onClear={clearFilters}>
                <FilterSelect label="Status" value={filters.status ?? ''} onChange={(status) => list.setFilters({ status: status || null })} options={INVOICE_STATUS_FILTERS} />
                <FilterDate label="Issued from" value={filters.from ?? ''} onChange={(from) => list.setFilters({ from: from || null })} />
                <FilterDate label="Issued to" value={filters.to ?? ''} onChange={(to) => list.setFilters({ to: to || null })} />
                <FilterSelect label="Sort" value={filters.sort} onChange={(sort) => list.setFilters({ sort })} options={DATE_SORTS.documents} />
            </FilterBar>

            {list.failure ? (
                <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
            ) : list.refreshing ? (
                <TableSkeleton label="Loading invoices" columns={6} />
            ) : rows.length === 0 ? (
                hasFilters ? (
                    <FilteredEmptyState onClear={clearFilters} />
                ) : (
                    <EmptyState icon={FileText} title="No invoices yet">
                        Invoices appear here when on-account orders are sent, or when prepaid orders are confirmed.
                    </EmptyState>
                )
            ) : (
                <DataTable
                    caption={`Invoices for ${company.name}, ${filters.sort === 'issued_asc' ? 'oldest' : 'newest'} first`}
                    columns={columns}
                    rows={rows}
                    rowKey={(r) => r.id}
                    rowLabel={(r) => r.number}
                    cardTitle={(r) => r.number}
                    sort={{ key: 'issued', direction: filters.sort === 'issued_asc' ? 'asc' : 'desc' }}
                    onSort={() => list.setFilters({ sort: filters.sort === 'issued_asc' ? 'issued_desc' : 'issued_asc' })}
                    rowAction={(r) => (
                        <Button asChild variant="outline" className="h-11">
                            <Link href={`/trade/invoices/${r.id}`}>View invoice</Link>
                        </Button>
                    )}
                    loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more invoices' }}
                />
            )}
        </TradeShell>
    );
}
