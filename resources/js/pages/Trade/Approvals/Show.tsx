/**
 * 05.2 §18.3 — one approval request. The buyer and order snapshot on the
 * left; on the right the totals, the buyer's limit, what is reserved and
 * the exact expiry in UK time. Approve opens a money confirmation;
 * Reject needs a reason the buyer is shown. The server decides — the page
 * never shows "approved" until it answers (05.16 §4).
 */
import { Link, router } from '@inertiajs/react';
import { Clock, PackageCheck, PackageX } from 'lucide-react';
import { useRef, useState } from 'react';

import { ConfirmDialog } from '@/components/trade/ConfirmDialog';
import { DataTable, type Column } from '@/components/trade/DataTable';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState, SuccessState } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ApiError } from '@/lib/api/client';
import { decideApproval } from '@/lib/api/credit';
import { formatUkDateTime } from '@/lib/dateTime';
import { subtractInts } from '@/lib/money';
import { APPROVAL_KIND, APPROVAL_STATUS, PAYMENT_METHOD, hoursUntil, type ApprovalKind, type ApprovalRow, type ApprovalStatus } from '@/lib/trade/approvals';
import { toast } from '@/stores/toastStore';

interface Line {
    sku_code: string;
    name: string;
    pack: string;
    pack_qty: number;
    base_qty: number;
    unit_price_net_e4: number;
    line_net_minor: number;
    line_tax_minor: number;
    line_gross_minor: number;
}

interface Detail extends ApprovalRow {
    company: { name: string; account_code: string };
    buyer: { name: string; email: string; role: string | null; order_limit_minor: number | null; requires_approval: boolean };
    order: {
        id: string;
        order_number: string;
        status: string;
        customer_reference: string | null;
        payment_method: string | null;
        fulfilment_type: string;
        placed_at: string | null;
        subtotal_net_minor: number;
        shipping_net_minor: number;
        tax_minor: number;
        total_gross_minor: number;
        delivery: string[] | null;
        lines: Line[];
    } | null;
    stock_reserved: boolean;
    requests: { kind: ApprovalKind; status: ApprovalStatus; decided_by: string | null; decided_at: string | null; reason: string | null }[];
}

const LINE_COLUMNS: Column<Line>[] = [
    { id: 'item', header: 'Item', inCardTitle: true, cell: (l) => <span><span className="font-medium">{l.name}</span><span className="block font-mono text-xs text-muted-foreground">{l.sku_code}</span></span> },
    { id: 'qty', header: 'Quantity', align: 'end', cell: (l) => <span>{l.pack_qty} × {l.pack}<span className="block text-xs text-muted-foreground">{l.base_qty} units</span></span> },
    { id: 'unit', header: 'Unit price (ex VAT)', align: 'end', priority: 'secondary', cell: (l) => <Money e4={l.unit_price_net_e4} /> },
    { id: 'net', header: 'Net', align: 'end', priority: 'secondary', cell: (l) => <Money minor={l.line_net_minor} /> },
    { id: 'gross', header: 'Gross', align: 'end', cell: (l) => <Money minor={l.line_gross_minor} /> },
];

export default function ApprovalShow({ approval }: { approval: Detail }) {
    const tz = useDisplayTimezone();
    const [dialog, setDialog] = useState<'approve' | 'reject' | null>(null);
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const idempotency = useRef<{ decision: string; key: string } | null>(null);

    const order = approval.order;
    const status = APPROVAL_STATUS[approval.status];
    const hoursLeft = hoursUntil(approval.expires_at);
    const overLimit = approval.buyer.order_limit_minor !== null && approval.gross_minor > approval.buyer.order_limit_minor;
    const creditPending = approval.requests.some((r) => r.kind === 'credit_exception' && r.status === 'pending');

    const decide = async (decision: 'approve' | 'reject') => {
        if (decision === 'reject' && reason.trim() === '') {
            setError('Give a reason. The buyer is shown it.');

            return;
        }
        if (idempotency.current?.decision !== decision) {
            idempotency.current = { decision, key: crypto.randomUUID() };
        }
        setPending(true);
        setError(null);
        try {
            const result = await decideApproval(approval.id, decision, idempotency.current.key, decision === 'reject' ? reason.trim() : undefined);
            setDialog(null);
            toast.success(decision === 'approve' ? `Order ${approval.order_number} approved` : `Order ${approval.order_number} rejected`, decision === 'approve' ? nextStep(result.data.order.status) : 'The buyer has been told and stock and credit released.');
            router.reload();
        } catch (e) {
            const failure = e as ApiError;
            setError(failure.message);
            // Decided or expired meanwhile: show what is true now.
            if (failure.status === 409) {
                router.reload({ only: ['approval'] });
            }
        } finally {
            setPending(false);
        }
    };

    return (
        <TradeShell title={`Approval ${approval.order_number}`}>
            <PageHeader
                breadcrumbs={[{ label: 'Approvals', href: '/trade/approvals' }, { label: approval.order_number }]}
                title={`Order ${approval.order_number}`}
                status={<StatusBadge tone={status.tone}>{approval.kind === 'credit_exception' && approval.status === 'pending' ? 'With accounts' : status.label}</StatusBadge>}
                description={<p>{APPROVAL_KIND[approval.kind]}. Placed by {approval.buyer.name} for {approval.company.name}.</p>}
                primaryAction={approval.can_decide ? <Button className="h-11" onClick={() => setDialog('approve')}>Approve order…</Button> : undefined}
                secondaryActions={approval.can_decide ? [{ label: 'Reject order…', onSelect: () => setDialog('reject') }] : []}
            />

            {approval.status !== 'pending' && (() => {
                const when = `${approval.decided_by ? `By ${approval.decided_by}, ` : ''}${formatUkDateTime(approval.decided_at, tz, true)}.`;
                const view = order ? <Button asChild variant="outline" className="h-11"><Link href={`/orders/${order.id}/confirmation`}>View the order</Link></Button> : undefined;

                return (
                    <div className="mb-6">
                        {approval.status === 'approved' ? (
                            <SuccessState title="This request was approved" action={view}>{when}</SuccessState>
                        ) : (
                            <EmptyState title={`This request was ${status.label.toLowerCase()}`} action={view}>
                                {when}
                                {approval.decision_reason && approval.status === 'rejected' ? ` Reason: ${approval.decision_reason}` : ''}
                            </EmptyState>
                        )}
                    </div>
                );
            })()}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <section aria-labelledby="buyer-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="buyer-heading" className="text-base font-semibold">Buyer</h2>
                        <dl className="mt-3 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Name</dt>
                            <dd>{approval.buyer.name}</dd>
                            <dt className="text-muted-foreground">Email</dt>
                            <dd className="break-all">{approval.buyer.email}</dd>
                            <dt className="text-muted-foreground">Role</dt>
                            <dd className="capitalize">{approval.buyer.role ?? 'No longer a member'}</dd>
                            <dt className="text-muted-foreground">Order limit</dt>
                            <dd>{approval.buyer.order_limit_minor === null ? 'No limit' : <Money minor={approval.buyer.order_limit_minor} />}{approval.buyer.requires_approval && ' · every order needs approval'}</dd>
                            {order?.customer_reference && (
                                <>
                                    <dt className="text-muted-foreground">Their reference</dt>
                                    <dd>{order.customer_reference}</dd>
                                </>
                            )}
                        </dl>
                    </section>

                    {order && (
                        <section aria-labelledby="lines-heading" className="flex flex-col gap-3">
                            <h2 id="lines-heading" className="text-base font-semibold">Items ({order.lines.length})</h2>
                            <DataTable caption={`Items on order ${order.order_number}`} columns={LINE_COLUMNS} rows={order.lines} rowKey={(l) => `${l.sku_code}-${l.pack}`} rowLabel={(l) => l.name} cardTitle={(l) => <span>{l.name}<span className="block font-mono text-xs font-normal text-muted-foreground">{l.sku_code}</span></span>} />
                        </section>
                    )}

                    {order && (
                        <section aria-labelledby="delivery-heading" className="rounded-xl border bg-background p-5">
                            <h2 id="delivery-heading" className="text-base font-semibold">{order.fulfilment_type === 'collection' ? 'Collection' : 'Delivery'}</h2>
                            {order.delivery ? <address className="mt-2 text-sm not-italic leading-relaxed">{order.delivery.map((l) => <span key={l} className="block">{l}</span>)}</address> : <p className="mt-2 text-sm text-muted-foreground">{order.fulfilment_type === 'collection' ? 'Collected from our counter.' : 'No delivery address recorded.'}</p>}
                        </section>
                    )}
                </div>

                <aside aria-label="Summary" className="flex flex-col gap-4 lg:sticky lg:top-24 lg:self-start">
                    <section className="rounded-xl border bg-background p-5">
                        <h2 className="text-base font-semibold">Order total</h2>
                        {order && (
                            <dl className="mt-3 space-y-2 text-sm">
                                <div className="flex justify-between gap-4"><dt className="text-muted-foreground">Net</dt><dd><Money minor={order.subtotal_net_minor} /></dd></div>
                                <div className="flex justify-between gap-4"><dt className="text-muted-foreground">Carriage (net)</dt><dd><Money minor={order.shipping_net_minor} /></dd></div>
                                <div className="flex justify-between gap-4"><dt className="text-muted-foreground">VAT</dt><dd><Money minor={order.tax_minor} /></dd></div>
                                <div className="flex justify-between gap-4 border-t pt-2 text-base font-semibold"><dt>Gross</dt><dd><Money minor={order.total_gross_minor} /></dd></div>
                            </dl>
                        )}
                        {overLimit && approval.buyer.order_limit_minor !== null && (
                            <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                <Money minor={subtractInts(approval.gross_minor, approval.buyer.order_limit_minor)} /> over {approval.buyer.name}&apos;s limit.
                            </p>
                        )}
                        <p className="mt-3 text-sm text-muted-foreground">Payment: {PAYMENT_METHOD[order?.payment_method ?? ''] ?? '—'}</p>
                    </section>

                    {approval.status === 'pending' && (
                        <section className="rounded-xl border bg-background p-5">
                            <h2 className="text-base font-semibold">What is held</h2>
                            <p className="mt-3 flex items-start gap-2 text-sm">
                                {approval.stock_reserved ? <PackageCheck className="mt-0.5 size-4 shrink-0 text-emerald-700" aria-hidden /> : <PackageX className="mt-0.5 size-4 shrink-0 text-amber-700" aria-hidden />}
                                {approval.stock_reserved ? 'Stock is reserved for this order.' : 'Stock not reserved — it is held only once accounts approve the credit.'}
                            </p>
                            <p className={`mt-3 flex items-start gap-2 text-sm ${hoursLeft < 6 ? 'font-semibold text-red-800' : ''}`}>
                                <Clock className="mt-0.5 size-4 shrink-0" aria-hidden />
                                <span>
                                    Expires {formatUkDateTime(approval.expires_at, tz, true)}
                                    <span className="block font-normal text-muted-foreground">{hoursLeft < 1 ? 'Less than an hour left.' : `${hoursLeft} hours left.`} After that it is cancelled automatically.</span>
                                </span>
                            </p>
                            {creditPending && approval.kind === 'buyer_limit' && <p className="mt-3 text-sm text-muted-foreground">Accounts must also approve the credit before the order is confirmed.</p>}
                        </section>
                    )}

                    <section className="rounded-xl border bg-background p-5">
                        <h2 className="text-base font-semibold">Decisions</h2>
                        <ul className="mt-3 space-y-3 text-sm">
                            {approval.requests.map((r) => (
                                <li key={r.kind}>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span>{r.kind === 'buyer_limit' ? 'Company approval' : 'Accounts credit approval'}</span>
                                        <StatusBadge tone={APPROVAL_STATUS[r.status].tone}>{APPROVAL_STATUS[r.status].label}</StatusBadge>
                                    </div>
                                    {r.decided_at && <p className="text-xs text-muted-foreground">{r.decided_by ? `${r.decided_by}, ` : ''}{formatUkDateTime(r.decided_at, tz)}</p>}
                                </li>
                            ))}
                        </ul>
                    </section>
                </aside>
            </div>

            <ConfirmDialog
                open={dialog === 'approve'}
                onOpenChange={(open) => { setDialog(open ? 'approve' : null); setError(null); }}
                title={`Approve order ${approval.order_number}?`}
                facts={[
                    { label: 'Company', value: approval.company.name },
                    { label: 'Order', value: approval.order_number },
                    { label: 'Buyer', value: approval.buyer.name },
                    { label: 'Total inc VAT', value: <Money minor={approval.gross_minor} /> },
                ]}
                consequences={order?.payment_method === 'card'
                    ? 'The buyer then has 2 hours to pay by card, or the order is cancelled.'
                    : creditPending
                      ? 'Your approval is recorded; accounts must still approve the credit before the order is confirmed.'
                      : `The order is confirmed and sent for picking${order?.payment_method === 'on_account' ? ', and the amount stays held against your credit' : ''}.`}
                confirmLabel="Approve order"
                pending={pending}
                error={error}
                onConfirm={() => decide('approve')}
            />
            <ConfirmDialog
                open={dialog === 'reject'}
                onOpenChange={(open) => { setDialog(open ? 'reject' : null); setError(null); }}
                title={`Reject order ${approval.order_number}?`}
                facts={[
                    { label: 'Company', value: approval.company.name },
                    { label: 'Order', value: approval.order_number },
                    { label: 'Total inc VAT', value: <Money minor={approval.gross_minor} /> },
                ]}
                consequences="The order is cancelled, its stock and credit released, and the buyer emailed your reason. This cannot be undone; the buyer can order again."
                confirmLabel="Reject order"
                destructive
                pending={pending}
                error={error}
                onConfirm={() => decide('reject')}
                confirmDisabled={reason.trim() === ''}
            >
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="reject-reason">Reason (shown to the buyer)</Label>
                    <Textarea id="reject-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} required aria-required className="min-h-24" />
                </div>
            </ConfirmDialog>
        </TradeShell>
    );
}

function nextStep(orderStatus: string | null): string {
    if (orderStatus === 'pending_payment') {
        return 'The buyer has been asked to pay by card within 2 hours.';
    }
    if (orderStatus === 'awaiting_approval') {
        return 'It now waits for accounts to approve the credit.';
    }

    return 'The order is confirmed and the buyer has been told.';
}
