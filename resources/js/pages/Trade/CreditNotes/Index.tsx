/**
 * 05.17 §4 — the company's credit notes, for owners and approvers: reason
 * and date filters, search by number, date sort, keyset "Load more". Each
 * row shows how much was set against the invoice's debt and how much
 * became spendable account balance — never both.
 */
import { Link } from '@inertiajs/react';
import { FileMinus } from 'lucide-react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { FilterBar, FilterDate, FilterSelect, type FilterChip } from '@/components/trade/FilterBar';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, FilteredEmptyState, TableSkeleton } from '@/components/trade/states';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { formatUkDate } from '@/lib/dateTime';
import { DATE_SORTS, dayIso, type CreditNoteRow } from '@/lib/trade/selfService';

interface Filters {
    reason: string | null;
    q: string | null;
    from: string | null;
    to: string | null;
    sort: string;
}

const REASONS = [
    { value: '', label: 'All reasons' },
    { value: 'return', label: 'Returned goods' },
    { value: 'cancellation', label: 'Cancelled goods' },
    { value: 'pricing_correction', label: 'Price correction' },
    { value: 'goodwill', label: 'Goodwill' },
    { value: 'other', label: 'Other' },
];

export default function CreditNotesIndex({ company, filters, rows, next_cursor }: { company: { name: string; account_code: string }; filters: Filters; rows: CreditNoteRow[]; next_cursor: string | null }) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({ ...filters }, next_cursor);

    const hasFilters = filters.reason !== null || filters.q !== null || filters.from !== null || filters.to !== null;
    const chips: FilterChip[] = [
        ...(filters.q ? [{ label: `Search: ${filters.q}`, onRemove: () => list.setFilters({ q: null }) }] : []),
        ...(filters.reason ? [{ label: `Reason: ${REASONS.find((r) => r.value === filters.reason)?.label ?? filters.reason}`, onRemove: () => list.setFilters({ reason: null }) }] : []),
        ...(filters.from ? [{ label: `From ${formatUkDate(dayIso(filters.from), tz)}`, onRemove: () => list.setFilters({ from: null }) }] : []),
        ...(filters.to ? [{ label: `To ${formatUkDate(dayIso(filters.to), tz)}`, onRemove: () => list.setFilters({ to: null }) }] : []),
    ];
    const clearFilters = () => list.setFilters({ reason: null, q: null, from: null, to: null });

    const columns: Column<CreditNoteRow>[] = [
        {
            id: 'note',
            header: 'Credit note',
            inCardTitle: true,
            cell: (r) => (
                <Link href={`/trade/credit-notes/${r.id}`} className="font-medium underline-offset-4 hover:underline">
                    {r.number}
                </Link>
            ),
        },
        { id: 'issued', header: 'Issued', sortKey: 'issued', cell: (r) => <span className="tabular-nums">{formatUkDate(r.issued_at, tz)}</span> },
        { id: 'reason', header: 'Reason', cell: (r) => r.reason_label },
        { id: 'invoice', header: 'Against invoice', priority: 'secondary', cell: (r) => r.invoice_number ?? '—' },
        { id: 'total', header: 'Total', align: 'end', cell: (r) => <Money minor={r.total_gross_minor} className="font-semibold" /> },
        { id: 'allocated', header: 'Set against debt', align: 'end', priority: 'secondary', cell: (r) => <Money minor={r.allocated_minor} /> },
        { id: 'balance', header: 'To account balance', align: 'end', priority: 'secondary', cell: (r) => <Money minor={r.to_balance_minor} /> },
    ];

    return (
        <TradeShell title="Credit notes">
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Credit notes' }]}
                title="Credit notes"
                description={<p>{company.name} · {company.account_code}. A credit note reduces what you owe on an invoice; any remainder becomes account balance.</p>}
            />

            <FilterBar search={{ value: filters.q ?? '', onChange: (q) => list.setFilters({ q }), label: 'Search by credit note number', placeholder: 'e.g. CN-000123' }} chips={chips} onClear={clearFilters}>
                <FilterSelect label="Reason" value={filters.reason ?? ''} onChange={(reason) => list.setFilters({ reason: reason || null })} options={REASONS} />
                <FilterDate label="Issued from" value={filters.from ?? ''} onChange={(from) => list.setFilters({ from: from || null })} />
                <FilterDate label="Issued to" value={filters.to ?? ''} onChange={(to) => list.setFilters({ to: to || null })} />
                <FilterSelect label="Sort" value={filters.sort} onChange={(sort) => list.setFilters({ sort })} options={DATE_SORTS.documents} />
            </FilterBar>

            {list.failure ? (
                <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
            ) : list.refreshing ? (
                <TableSkeleton label="Loading credit notes" />
            ) : rows.length === 0 ? (
                hasFilters ? (
                    <FilteredEmptyState onClear={clearFilters} />
                ) : (
                    <EmptyState icon={FileMinus} title="No credit notes">
                        Credit notes are issued for returns, cancelled goods and corrections, and appear here.
                    </EmptyState>
                )
            ) : (
                <DataTable
                    caption={`Credit notes for ${company.name}`}
                    columns={columns}
                    rows={rows}
                    rowKey={(r) => r.id}
                    rowLabel={(r) => r.number}
                    cardTitle={(r) => r.number}
                    sort={{ key: 'issued', direction: filters.sort === 'issued_asc' ? 'asc' : 'desc' }}
                    onSort={() => list.setFilters({ sort: filters.sort === 'issued_asc' ? 'issued_desc' : 'issued_asc' })}
                    rowAction={(r) => (
                        <Button asChild variant="outline" className="h-11">
                            <Link href={`/trade/credit-notes/${r.id}`}>View credit note</Link>
                        </Button>
                    )}
                    loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more credit notes' }}
                />
            )}
        </TradeShell>
    );
}
