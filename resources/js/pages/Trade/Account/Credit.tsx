/**
 * 05.2 §18.3 — account credit, for the company's owners and approvers:
 * limit, used, held, available and balance; whether on account is open
 * and, if not, why and what to do; an overdue alert; ageing; and the
 * outstanding invoices, oldest due first, with keyset "Load more".
 * Integer pence throughout; amounts never computed from formatted text.
 */
import { Link } from '@inertiajs/react';
import { Landmark, TriangleAlert } from 'lucide-react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { SummaryCard } from '@/components/trade/SummaryCard';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { formatUkDate } from '@/lib/dateTime';
import { sumInts } from '@/lib/money';

interface Summary {
    status: string;
    suspension_reason: string | null;
    payment_terms: string;
    limit_minor: number;
    used_minor: number;
    held_minor: number;
    available_minor: number;
    over_limit_minor: number;
    balance_minor: number;
    on_account: { allowed: boolean; code: string | null; message: string | null };
    overdue: { count: number; amount_minor: number; oldest_due_at: string | null };
    ageing: { bucket: string; label: string; count: number; amount_minor: number }[];
}

interface InvoiceRow {
    id: string;
    invoice_number: string;
    issued_at: string;
    due_at: string | null;
    days_overdue: number;
    total_gross_minor: number;
    paid_minor: number;
    credited_minor: number;
    outstanding_minor: number;
}

const TERMS: Record<string, string> = { prepay: 'Pay in advance', net7: '7 days', net14: '14 days', net30: '30 days', net60: '60 days' };

export default function Credit({ company, summary, rows, next_cursor }: { company: { name: string; account_code: string }; summary: Summary; rows: InvoiceRow[]; next_cursor: string | null }) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({}, next_cursor);

    const columns: Column<InvoiceRow>[] = [
        { id: 'invoice', header: 'Invoice', cell: (r) => <span className="font-medium">{r.invoice_number}</span> },
        { id: 'issued', header: 'Issued', priority: 'secondary', cell: (r) => <span className="tabular-nums">{formatUkDate(r.issued_at, tz)}</span> },
        {
            id: 'due',
            header: 'Due',
            cell: (r) => (
                <span className="flex flex-col items-start gap-1 tabular-nums">
                    {r.due_at ? formatUkDate(r.due_at, tz) : 'On receipt'}
                    {r.days_overdue > 0 ? <StatusBadge tone="danger">{`Overdue ${r.days_overdue} day${r.days_overdue === 1 ? '' : 's'}`}</StatusBadge> : <StatusBadge tone="neutral">Not yet due</StatusBadge>}
                </span>
            ),
        },
        { id: 'total', header: 'Total', align: 'end', priority: 'secondary', cell: (r) => <Money minor={r.total_gross_minor} /> },
        { id: 'paid', header: 'Paid and credited', align: 'end', priority: 'secondary', cell: (r) => <Money minor={sumInts([r.paid_minor, r.credited_minor])} /> },
        { id: 'outstanding', header: 'Outstanding', align: 'end', cell: (r) => <Money minor={r.outstanding_minor} className="font-semibold" /> },
    ];

    return (
        <TradeShell title="Credit">
            <PageHeader
                breadcrumbs={[{ label: 'Account', href: '/account' }, { label: 'Credit' }]}
                title="Account credit"
                description={<p>{company.name} · {company.account_code} · Terms: {TERMS[summary.payment_terms] ?? summary.payment_terms}</p>}
                primaryAction={<Button asChild className="h-11"><Link href="/trade/account/balance">Balance history</Link></Button>}
            />

            {!summary.on_account.allowed && (
                <div role="status" className="mb-6 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <TriangleAlert className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <div>
                        <p className="font-semibold">On-account ordering is not available</p>
                        <p className="mt-1">{summary.on_account.message}</p>
                        <p className="mt-1">You can still pay for orders by card or bank transfer{summary.status === 'suspended' && summary.suspension_reason !== 'debt' ? ' once accounts reinstate the account' : ''}. Questions: <Link href="/contact" className="underline underline-offset-4">contact accounts</Link>.</p>
                    </div>
                </div>
            )}

            {summary.overdue.count > 0 && (
                <div role="alert" className="mb-6 flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                    <TriangleAlert className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <p>
                        <span className="font-semibold">{summary.overdue.count} invoice{summary.overdue.count === 1 ? '' : 's'} overdue: <Money minor={summary.overdue.amount_minor} /></span>
                        {summary.overdue.oldest_due_at && <> — the oldest was due {formatUkDate(summary.overdue.oldest_due_at, tz)}.</>} Overdue debt blocks on-account orders until it is paid.
                    </p>
                </div>
            )}

            <dl className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <SummaryCard label="Credit limit" value={<Money minor={summary.limit_minor} />} />
                <SummaryCard label="Used (invoiced, unpaid)" value={<Money minor={summary.used_minor} />} />
                <SummaryCard label="Held (orders not yet invoiced)" value={<Money minor={summary.held_minor} />} />
                <SummaryCard
                    label="Available to order on account"
                    value={<Money minor={summary.available_minor} />}
                    note={summary.over_limit_minor > 0 ? <span className="font-medium text-red-800">Over the limit by <Money minor={summary.over_limit_minor} /></span> : 'Limit − used − held'}
                    emphasis
                />
                <SummaryCard label="Account balance" value={<Money minor={summary.balance_minor} />} note="Your money to spend; not credit" />
            </dl>

            <section aria-labelledby="ageing-heading" className="mb-8">
                <h2 id="ageing-heading" className="mb-3 text-lg font-semibold">Unpaid invoices by age</h2>
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {summary.ageing.map((b) => (
                        <li key={b.bucket} className="rounded-xl border bg-background p-4">
                            <p className="text-sm text-muted-foreground">{b.label}</p>
                            <p className="mt-1 text-lg font-semibold"><Money minor={b.amount_minor} /></p>
                            <p className="text-xs text-muted-foreground tabular-nums">{b.count} invoice{b.count === 1 ? '' : 's'}</p>
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="invoices-heading">
                <h2 id="invoices-heading" className="mb-3 text-lg font-semibold">Outstanding invoices</h2>
                {list.failure ? (
                    <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
                ) : list.refreshing ? (
                    <TableSkeleton label="Loading invoices" />
                ) : rows.length === 0 ? (
                    <EmptyState icon={Landmark} title="Nothing outstanding" action={<Button asChild variant="outline" className="h-11"><Link href="/order-pad">Go to the order pad</Link></Button>}>
                        Every invoice is paid. On-account orders you place are invoiced as they are sent.
                    </EmptyState>
                ) : (
                    <DataTable
                        caption={`Outstanding invoices for ${company.name}, oldest due first`}
                        columns={columns}
                        rows={rows}
                        rowKey={(r) => r.id}
                        rowLabel={(r) => r.invoice_number}
                        cardTitle={(r) => r.invoice_number}
                        loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load more invoices' }}
                    />
                )}
            </section>
        </TradeShell>
    );
}
