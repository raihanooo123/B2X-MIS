/**
 * 05.2 §18.3, 05.4 §15.3 — the account balance and its append-only
 * ledger: credit notes beyond what was owed, applications, payouts and
 * reversals, newest first, each with the balance after it. Paying a
 * balance out is accounts' (second-person approval); the latest payouts
 * are listed here.
 */
import { Link } from '@inertiajs/react';
import { Wallet } from 'lucide-react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/trade/states';
import { StatusBadge, type StatusTone } from '@/components/trade/StatusBadge';
import { SummaryCard } from '@/components/trade/SummaryCard';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { useKeysetList } from '@/components/trade/useKeysetList';
import { Button } from '@/components/ui/button';
import { formatUkDate, formatUkDateTime } from '@/lib/dateTime';
import { MOVEMENT_TYPE } from '@/lib/trade/approvals';
import { cn } from '@/lib/utils';

interface Movement {
    id: number;
    occurred_at: string;
    type: string;
    reference: string | null;
    amount_minor: number;
    balance_after_minor: number | null;
}

interface Payout {
    id: string;
    method: string;
    amount_minor: number;
    status: 'pending' | 'processing' | 'paid' | 'failed';
    requested_at: string;
    completed_at: string | null;
}

const PAYOUT_STATUS: Record<Payout['status'], { label: string; tone: StatusTone }> = {
    pending: { label: 'Awaiting approval', tone: 'pending' },
    processing: { label: 'Being paid', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
    failed: { label: 'Not paid — returned to balance', tone: 'danger' },
};

export default function Balance({ company, balance_minor, payouts, rows, next_cursor }: { company: { name: string; account_code: string }; balance_minor: number; payouts: Payout[]; rows: Movement[]; next_cursor: string | null }) {
    const tz = useDisplayTimezone();
    const list = useKeysetList({}, next_cursor);

    const columns: Column<Movement>[] = [
        { id: 'date', header: 'Date', cell: (m) => <span className="tabular-nums">{formatUkDateTime(m.occurred_at, tz)}</span> },
        { id: 'type', header: 'Movement', cell: (m) => MOVEMENT_TYPE[m.type] ?? m.type },
        { id: 'reference', header: 'Reference', cell: (m) => <span className="font-mono text-xs">{m.reference ?? '—'}</span> },
        { id: 'amount', header: 'Amount', align: 'end', cell: (m) => <Money minor={m.amount_minor} className={cn(m.amount_minor > 0 ? 'text-emerald-800' : 'text-foreground')} /> },
        { id: 'after', header: 'Balance after', align: 'end', priority: 'secondary', cell: (m) => (m.balance_after_minor === null ? '—' : <Money minor={m.balance_after_minor} />) },
    ];

    return (
        <TradeShell title="Account balance">
            <PageHeader
                breadcrumbs={[{ label: 'Account', href: '/account' }, { label: 'Credit', href: '/trade/account/credit' }, { label: 'Balance' }]}
                title="Account balance"
                description={<p>Money held for {company.name}, such as credit notes worth more than you owed. It is yours to spend — it is not credit. To have it paid back to you, contact accounts.</p>}
                primaryAction={<Button asChild className="h-11"><Link href="/trade/account/credit">Credit summary</Link></Button>}
            />

            <dl className="mb-8 grid gap-4 sm:max-w-sm">
                <SummaryCard label="Spendable balance" value={<Money minor={balance_minor} />} emphasis />
            </dl>

            {payouts.length > 0 && (
                <section aria-labelledby="payouts-heading" className="mb-8">
                    <h2 id="payouts-heading" className="mb-3 text-lg font-semibold">Payouts</h2>
                    <ul className="flex flex-col gap-2">
                        {payouts.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background px-4 py-3 text-sm">
                                <span className="tabular-nums">{formatUkDate(p.requested_at, tz)} · to the original card</span>
                                <Money minor={p.amount_minor} className="font-semibold" />
                                <StatusBadge tone={PAYOUT_STATUS[p.status].tone}>{PAYOUT_STATUS[p.status].label}</StatusBadge>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="ledger-heading">
                <h2 id="ledger-heading" className="mb-3 text-lg font-semibold">Balance history</h2>
                {list.failure ? (
                    <ErrorState message={list.failure.message} reference={list.failure.reference} onRetry={list.retry} />
                ) : list.refreshing ? (
                    <TableSkeleton label="Loading balance history" columns={5} />
                ) : rows.length === 0 ? (
                    <EmptyState icon={Wallet} title="No balance movements yet" action={<Button asChild variant="outline" className="h-11"><Link href="/trade/account/credit">See your credit</Link></Button>}>
                        When a credit note is worth more than you owe, the difference appears here to spend on later orders.
                    </EmptyState>
                ) : (
                    <DataTable
                        caption={`Balance history for ${company.name}, newest first`}
                        columns={columns}
                        rows={rows}
                        rowKey={(m) => String(m.id)}
                        rowLabel={(m) => `${MOVEMENT_TYPE[m.type] ?? m.type} ${formatUkDate(m.occurred_at, tz)}`}
                        cardTitle={(m) => `${MOVEMENT_TYPE[m.type] ?? m.type} · ${formatUkDate(m.occurred_at, tz)}`}
                        loadMore={{ hasMore: list.hasMore, loading: list.loadingMore, onLoadMore: list.loadMore, label: 'Load older movements' }}
                    />
                )}
            </section>
        </TradeShell>
    );
}
