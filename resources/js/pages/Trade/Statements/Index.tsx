/**
 * 05.17 §4 — statements, for owners and approvers: choose a range of UK
 * calendar days (current month by default, at most 12 months), generate
 * a fixed as-of statement, and find earlier ones. Generate statement is
 * the one primary action; the result opens on its own page.
 */
import { Link, router } from '@inertiajs/react';
import { Loader2, ScrollText } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { ErrorSummary } from '@/components/trade/ErrorSummary';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api/client';
import { generateStatement } from '@/lib/api/documents';
import { formatUkDate, formatUkDateTime } from '@/lib/dateTime';
import { dayIso, type StatementRow } from '@/lib/trade/selfService';

interface Props {
    company: { name: string; account_code: string };
    defaults: { from_on: string; to_on: string };
    max_months: number;
    rows: StatementRow[];
    next_cursor: string | null;
}

export default function StatementsIndex({ company, defaults, max_months, rows, next_cursor }: Props) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({}, next_cursor);
    const [fromOn, setFromOn] = useState(defaults.from_on);
    const [toOn, setToOn] = useState(defaults.to_on);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            const result = await generateStatement(fromOn, toOn);
            router.visit(result.data.url);
        } catch (error) {
            if (error instanceof ApiError && error.details.length > 0) {
                setErrors(Object.fromEntries(error.details.map((d) => [d.field === 'to_on' ? 'to_on' : 'from_on', d.message])));
            } else {
                setErrors({ from_on: error instanceof ApiError ? error.message : 'The connection failed. Nothing was created. Try again.' });
            }
            setBusy(false);
        }
    };

    const columns: Column<StatementRow>[] = [
        {
            id: 'period',
            header: 'Period',
            inCardTitle: true,
            cell: (r) => (
                <Link href={`/trade/statements/${r.id}`} className="font-medium underline-offset-4 hover:underline">
                    {formatUkDate(dayIso(r.from_on), tz)} – {formatUkDate(dayIso(r.to_on), tz)}
                </Link>
            ),
        },
        { id: 'asat', header: 'Figures as at', cell: (r) => <span className="tabular-nums">{formatUkDateTime(r.cutoff_at, tz)}</span> },
        { id: 'by', header: 'Requested by', priority: 'secondary', cell: (r) => r.requested_by ?? '—' },
        {
            id: 'pdf',
            header: 'PDF',
            cell: (r) => (r.document_status === 'ready' ? <StatusBadge tone="success">Ready</StatusBadge> : r.document_status === 'failed' ? <StatusBadge tone="danger">Not prepared</StatusBadge> : <StatusBadge tone="pending">Preparing</StatusBadge>),
        },
    ];

    return (
        <TradeShell title="Statements">
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Statements' }]}
                title="Statements"
                description={<p>{company.name} · {company.account_code}. A statement shows your account as at the moment you request it; later payments appear on the next one.</p>}
            />

            <section aria-labelledby="new-heading" className="mb-8 rounded-xl border bg-background p-5">
                <h2 id="new-heading" className="mb-1 text-lg font-semibold">
                    New statement
                </h2>
                <p className="mb-4 text-sm text-muted-foreground">Up to {max_months} months at a time. For older history, request each period separately.</p>
                <ErrorSummary errors={errors} fieldIds={{ from_on: 'statement-from', to_on: 'statement-to' }} />
                <form onSubmit={submit} className="flex flex-col gap-4 md:flex-row md:items-end" noValidate>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="statement-from">From</Label>
                        <Input id="statement-from" type="date" required value={fromOn} max={toOn} onChange={(e) => setFromOn(e.target.value)} className="h-11" aria-invalid={errors.from_on !== undefined} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="statement-to">To</Label>
                        <Input id="statement-to" type="date" required value={toOn} min={fromOn} max={defaults.to_on} onChange={(e) => setToOn(e.target.value)} className="h-11" aria-invalid={errors.to_on !== undefined} />
                    </div>
                    <Button type="submit" className="h-11" disabled={busy || fromOn === '' || toOn === ''}>
                        {busy ? <Loader2 className="animate-spin" aria-hidden /> : <ScrollText aria-hidden />} Generate statement
                    </Button>
                </form>
            </section>

            <section aria-labelledby="earlier-heading">
                <h2 id="earlier-heading" className="mb-3 text-lg font-semibold">
                    Earlier statements
                </h2>
                {list.failure ? (
                    <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
                ) : list.refreshing ? (
                    <TableSkeleton label="Loading statements" columns={4} />
                ) : rows.length === 0 ? (
                    <EmptyState icon={ScrollText} title="No statements yet">
                        Statements you generate are kept here, unchanged, with their PDF.
                    </EmptyState>
                ) : (
                    <DataTable
                        caption={`Statements for ${company.name}, newest first`}
                        columns={columns}
                        rows={rows}
                        rowKey={(r) => r.id}
                        rowLabel={(r) => `${r.from_on} to ${r.to_on}`}
                        cardTitle={(r) => `${formatUkDate(dayIso(r.from_on), tz)} – ${formatUkDate(dayIso(r.to_on), tz)}`}
                        rowAction={(r) => (
                            <Button asChild variant="outline" className="h-11">
                                <Link href={`/trade/statements/${r.id}`}>View statement</Link>
                            </Button>
                        )}
                        loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more statements' }}
                    />
                )}
            </section>
        </TradeShell>
    );
}
