/**
 * 05.1 §7.3, §14.2 — the company's saved lists: search, sort by name or
 * last change, "Load more". Shared by everyone on the account; buying
 * roles may save the current basket as a new list. Lists hold products and
 * quantities only — never prices.
 */
import { Link, router } from '@inertiajs/react';
import { ListChecks, Loader2, Save } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { FilterBar, FilterSelect, type FilterChip } from '@/components/trade/FilterBar';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, FilteredEmptyState, TableSkeleton } from '@/components/trade/states';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api/client';
import { createSavedList, type SavedListSummary } from '@/lib/api/orderTools';
import { formatUkDate } from '@/lib/dateTime';

interface Props {
    filters: { sort: string; q: string | null };
    can_edit: boolean;
    rows: SavedListSummary[];
    next_cursor: string | null;
}

const SORTS = [
    { value: 'name', label: 'Name, A to Z' },
    { value: 'updated', label: 'Recently changed' },
];

export default function SavedListsIndex({ filters, can_edit, rows, next_cursor }: Props) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({ ...filters }, next_cursor);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const chips: FilterChip[] = filters.q ? [{ label: `Search: ${filters.q}`, onRemove: () => list.setFilters({ q: null }) }] : [];

    const save = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const result = await createSavedList(name, true);
            router.visit(result.data.url);
        } catch (err) {
            setError(err instanceof ApiError ? (err.details[0]?.message ?? err.message) : 'The connection failed. Nothing was saved.');
            setBusy(false);
        }
    };

    const columns: Column<SavedListSummary>[] = [
        { id: 'name', header: 'List', inCardTitle: true, cell: (r) => <Link href={`/trade/saved-lists/${r.id}`} className="font-medium underline-offset-4 hover:underline">{r.name}</Link> },
        { id: 'lines', header: 'Products', align: 'end', cell: (r) => <span className="tabular-nums">{r.line_count}</span> },
        { id: 'by', header: 'Created by', priority: 'secondary', cell: (r) => r.created_by ?? '—' },
        { id: 'updated', header: 'Last changed', cell: (r) => <span className="tabular-nums">{formatUkDate(r.updated_at, tz)}</span> },
    ];

    return (
        <TradeShell title="Saved lists">
            <PageHeader
                breadcrumbs={[{ label: 'Order pad', href: '/order-pad' }, { label: 'Saved lists' }]}
                title="Saved lists"
                description={<p>Products you order again and again, shared with everyone on your account. Using a list shows you what has changed before anything goes in your cart.</p>}
            />

            {can_edit && (
                <form onSubmit={save} className="mb-6 flex flex-col gap-3 rounded-xl border bg-background p-4 md:flex-row md:items-end" noValidate>
                    <div className="flex flex-1 flex-col gap-1.5">
                        <Label htmlFor="list-name">Save your current cart as a list</Label>
                        <Input id="list-name" value={name} maxLength={80} onChange={(e) => setName(e.target.value)} placeholder="e.g. Weekly cleaning order" className="h-11" />
                        {error && <p role="alert" className="text-sm text-red-900">{error}</p>}
                    </div>
                    <Button type="submit" className="h-11" disabled={busy || name.trim() === ''}>
                        {busy ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />} Save current cart
                    </Button>
                </form>
            )}

            <FilterBar search={{ value: filters.q ?? '', onChange: (q) => list.setFilters({ q }), label: 'Search lists', placeholder: 'List name' }} chips={chips} onClear={() => list.setFilters({ q: null })}>
                <FilterSelect label="Sort" value={filters.sort} onChange={(sort) => list.setFilters({ sort })} options={SORTS} />
            </FilterBar>

            {list.failure ? (
                <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
            ) : list.refreshing ? (
                <TableSkeleton label="Loading lists" columns={4} />
            ) : rows.length === 0 ? (
                filters.q ? (
                    <FilteredEmptyState onClear={() => list.setFilters({ q: null })} />
                ) : (
                    <EmptyState icon={ListChecks} title="No saved lists yet">
                        {can_edit ? 'Fill your cart from the order pad, then save it here as a list to reuse.' : 'Lists your colleagues save appear here.'}
                    </EmptyState>
                )
            ) : (
                <DataTable
                    caption="Saved lists"
                    columns={columns}
                    rows={rows}
                    rowKey={(r) => r.id}
                    rowLabel={(r) => r.name}
                    cardTitle={(r) => r.name}
                    rowAction={(r) => <Button asChild variant="outline" className="h-11"><Link href={`/trade/saved-lists/${r.id}`}>Open</Link></Button>}
                    loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more lists' }}
                />
            )}
        </TradeShell>
    );
}
