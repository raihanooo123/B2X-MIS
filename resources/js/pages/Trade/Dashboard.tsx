/**
 * 05.17 §4 — the trade dashboard, for every member of the acting company:
 * account status and terms; what is waiting (approval, payment, in
 * progress) kept visibly apart; recent orders; quick tools. Owners and
 * approvers also see credit, overdue debt and spendable balance — the
 * server sends those figures to nobody else. Start order is the one
 * primary action.
 */
import { Link } from '@inertiajs/react';
import { ClipboardCheck, Clock, CreditCard, Package, ShoppingCart, TriangleAlert, Truck } from 'lucide-react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { SummaryCard } from '@/components/trade/SummaryCard';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { Button } from '@/components/ui/button';
import { formatUkDate } from '@/lib/dateTime';
import { orderTone, type OrderRow } from '@/lib/trade/selfService';

interface Dashboard {
    account: {
        name: string;
        account_code: string;
        status: string;
        payment_terms: string;
        payment_terms_label: string;
        on_account: { allowed: boolean; message: string | null };
    };
    waiting: { awaiting_approval: number; pending_payment: number; in_progress: number };
    pending_approvals: number | null;
    recent_orders: OrderRow[];
    finance: {
        limit_minor: number;
        available_minor: number;
        over_limit_minor: number;
        balance_minor: number;
        overdue: { count: number; amount_minor: number; oldest_due_at: string | null };
    } | null;
}

function WaitingTile({ icon: Icon, label, count, href, note }: { icon: typeof Clock; label: string; count: number; href: string; note: string }) {
    return (
        <li>
            <Link href={href} className="flex min-h-11 items-start gap-3 rounded-xl border bg-background p-4 transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon className="size-5" aria-hidden />
                </span>
                <span className="flex flex-col">
                    <span className="text-sm text-muted-foreground">{label}</span>
                    <span className="text-2xl font-semibold tabular-nums">{count}</span>
                    <span className="text-xs text-muted-foreground">{note}</span>
                </span>
            </Link>
        </li>
    );
}

export default function TradeDashboard({ dashboard }: { dashboard: Dashboard }) {
    const tz = useDisplayTimezone();
    const { account, waiting, finance } = dashboard;

    const columns: Column<OrderRow>[] = [
        { id: 'order', header: 'Order', inCardTitle: true, cell: (r) => <span className="font-medium">{r.order_number}</span> },
        { id: 'reference', header: 'Your reference', priority: 'secondary', cell: (r) => r.customer_reference ?? '—' },
        { id: 'placed', header: 'Placed', cell: (r) => <span className="tabular-nums">{formatUkDate(r.placed_at, tz)}</span> },
        { id: 'status', header: 'Status', cell: (r) => <StatusBadge tone={orderTone(r.status)}>{r.status_label}</StatusBadge> },
        { id: 'total', header: 'Total inc VAT', align: 'end', cell: (r) => <Money minor={r.total_gross_minor} /> },
    ];

    return (
        <TradeShell title="Dashboard">
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard' }]}
                title={account.name}
                description={<p>Account {account.account_code} · Payment terms: {account.payment_terms_label}</p>}
                primaryAction={
                    <Button asChild className="h-11">
                        <Link href="/order-pad">
                            <ShoppingCart aria-hidden /> Start order
                        </Link>
                    </Button>
                }
            />

            {!account.on_account.allowed && (
                <div role="status" className="mb-6 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <TriangleAlert className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <p>
                        <span className="font-semibold">On-account ordering is not available. </span>
                        {account.on_account.message} You can still pay by card or bank transfer.
                    </p>
                </div>
            )}

            {finance && finance.overdue.count > 0 && (
                <div role="alert" className="mb-6 flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                    <TriangleAlert className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <p>
                        <span className="font-semibold">
                            {finance.overdue.count} invoice{finance.overdue.count === 1 ? '' : 's'} overdue: <Money minor={finance.overdue.amount_minor} />.
                        </span>{' '}
                        <Link href="/trade/invoices?status=overdue" className="underline underline-offset-4">
                            See overdue invoices
                        </Link>
                    </p>
                </div>
            )}

            {dashboard.pending_approvals !== null && dashboard.pending_approvals > 0 && (
                <div role="status" className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-violet-300 bg-violet-50 p-4 text-sm text-violet-950">
                    <p className="flex items-center gap-2">
                        <ClipboardCheck className="size-5" aria-hidden />
                        <span>
                            <span className="font-semibold tabular-nums">{dashboard.pending_approvals}</span> order{dashboard.pending_approvals === 1 ? '' : 's'} waiting for your approval.
                        </span>
                    </p>
                    <Button asChild variant="outline" className="h-11 bg-background">
                        <Link href="/trade/approvals">Review approvals</Link>
                    </Button>
                </div>
            )}

            {finance && (
                <dl className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <SummaryCard
                        label="Available to order on account"
                        value={<Money minor={finance.available_minor} />}
                        note={finance.over_limit_minor > 0 ? <span className="font-medium text-red-800">Over the limit by <Money minor={finance.over_limit_minor} /></span> : <>Credit limit <Money minor={finance.limit_minor} /></>}
                        emphasis
                    />
                    <SummaryCard label="Overdue" value={<Money minor={finance.overdue.amount_minor} />} note={finance.overdue.count === 0 ? 'Nothing overdue' : `${finance.overdue.count} invoice${finance.overdue.count === 1 ? '' : 's'}`} />
                    <SummaryCard label="Account balance" value={<Money minor={finance.balance_minor} />} note="Your money to spend; it never raises your credit limit" />
                </dl>
            )}

            <section aria-labelledby="waiting-heading" className="mb-8">
                <h2 id="waiting-heading" className="mb-3 text-lg font-semibold">
                    Orders in progress
                </h2>
                <ul className="grid gap-3 sm:grid-cols-3">
                    <WaitingTile icon={ClipboardCheck} label="Awaiting approval" count={waiting.awaiting_approval} href="/trade/orders?status=awaiting_approval" note="Stock is not reserved until approved" />
                    <WaitingTile icon={CreditCard} label="Awaiting payment" count={waiting.pending_payment} href="/trade/orders?status=pending_payment" note="Paid before they are picked" />
                    <WaitingTile icon={Truck} label="Being prepared" count={waiting.in_progress} href="/trade/orders?status=in_progress" note="Confirmed, picking or partly sent" />
                </ul>
            </section>

            <section aria-labelledby="recent-heading">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="recent-heading" className="text-lg font-semibold">
                        Recent orders
                    </h2>
                    <Link href="/trade/orders" className="inline-flex min-h-11 items-center text-sm underline underline-offset-4">
                        All orders
                    </Link>
                </div>
                {dashboard.recent_orders.length === 0 ? (
                    <EmptyState icon={Package} title="No orders yet" action={<Button asChild className="h-11"><Link href="/order-pad">Start an order</Link></Button>}>
                        Orders placed for {account.name} by anyone on your team appear here.
                    </EmptyState>
                ) : (
                    <DataTable
                        caption={`Recent orders for ${account.name}`}
                        columns={columns}
                        rows={dashboard.recent_orders}
                        rowKey={(r) => r.id}
                        rowLabel={(r) => r.order_number}
                        cardTitle={(r) => r.order_number}
                        rowAction={(r) => (
                            <Button asChild variant="outline" className="h-11">
                                <Link href={`/trade/orders/${r.id}`}>View</Link>
                            </Button>
                        )}
                    />
                )}
            </section>
        </TradeShell>
    );
}
