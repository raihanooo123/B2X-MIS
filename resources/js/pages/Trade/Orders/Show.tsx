/**
 * 05.17 §4 — one company order: lines and the shipment timeline on the
 * left; totals, payment and approval summary on the right. Everything is
 * the order's immutable snapshot — nothing is re-priced. Invoices and
 * credit notes are linked for owners and approvers only.
 */
import { Link, router } from '@inertiajs/react';
import { CheckCircle2, CreditCard, Loader2, Package, RotateCcw, Truck } from 'lucide-react';
import { useState } from 'react';

import { DataTable, type Column } from '@/components/trade/DataTable';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api/client';
import { reorderPreview } from '@/lib/api/orderTools';
import { formatUkDate, formatUkDateTime } from '@/lib/dateTime';
import { formatBasisPoints } from '@/lib/money';
import { orderTone, type OrderRow } from '@/lib/trade/selfService';

interface Line {
    line_no: number;
    sku_code: string;
    name: string;
    pack: string;
    pack_qty: number;
    pack_base_units: number;
    base_qty: number;
    dispatched_base_qty: number;
    cancelled_base_qty: number;
    returned_base_qty: number;
    unit_price_net_e4: number;
    tax_rate_bp: number;
    line_net_minor: number;
    line_tax_minor: number;
    line_gross_minor: number;
}

interface OrderDetail extends OrderRow {
    fulfilment_type: string;
    payment_method: string | null;
    payment_status: string;
    stock_reserved: boolean;
    delivery_address: string[];
    billing_address: string[];
    totals: { subtotal_net_minor: number; discount_net_minor: number; shipping_net_minor: number; tax_minor: number; total_gross_minor: number };
    lines: Line[];
    shipments: { id: string; status: string; carrier: string | null; tracking_number: string | null; parcel_count: number | null; picked_at: string | null; dispatched_at: string | null }[];
    timeline: { at: string; label: string }[];
    approvals: { kind: string; status: string; requested_at: string; decided_at: string | null; decided_by: string | null }[];
    invoices: { id: string; number: string; status: string; issued_at: string; total_gross_minor: number }[] | null;
    credit_notes: { id: string; number: string; issued_at: string; total_gross_minor: number }[] | null;
    viewer_role: string | null;
}

const PAYMENT: Record<string, string> = {
    on_account: 'On account',
    card: 'Card',
    bank_transfer: 'Bank transfer',
    cash_at_collection: 'Pay at collection',
};

const PAYMENT_STATUS: Record<string, string> = {
    unpaid: 'Not paid',
    deposit_paid: 'Deposit paid',
    paid: 'Paid',
    part_refunded: 'Part refunded',
    refunded: 'Refunded',
    on_account: 'Invoiced on account',
};

function Row({ label, value, strong = false }: { label: string; value: React.ReactNode; strong?: boolean }) {
    return (
        <div className="flex justify-between gap-4 py-1.5 text-sm">
            <dt className={strong ? 'font-semibold' : 'text-muted-foreground'}>{label}</dt>
            <dd className={strong ? 'font-semibold' : undefined}>{value}</dd>
        </div>
    );
}

export default function OrderShow({ order }: { order: OrderDetail }) {
    const tz = useDisplayTimezone();
    // 05.1 §14.1: buying roles reorder — into the same check-before-adding preview, never a new order.
    const canReorder = order.placed_at !== null && ['owner', 'buyer', 'approver'].includes(order.viewer_role ?? '');
    const [reordering, setReordering] = useState(false);
    const [reorderError, setReorderError] = useState<string | null>(null);
    const reorder = async () => {
        setReordering(true);
        setReorderError(null);
        try {
            const result = await reorderPreview(order.id);
            router.visit(result.data.url ?? `/trade/order-tools/imports/${result.data.id}`);
        } catch (e) {
            setReorderError(e instanceof ApiError ? e.message : 'The connection failed. Try again.');
            setReordering(false);
        }
    };

    const columns: Column<Line>[] = [
        {
            id: 'item',
            header: 'Item',
            inCardTitle: true,
            cell: (l) => (
                <span className="flex flex-col">
                    <span className="font-medium">{l.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">{l.sku_code}</span>
                </span>
            ),
        },
        {
            id: 'qty',
            header: 'Quantity',
            cell: (l) => (
                <span className="tabular-nums">
                    {l.pack_qty} × {l.pack}
                    <span className="block text-xs text-muted-foreground">{l.base_qty.toLocaleString('en-GB')} units</span>
                </span>
            ),
        },
        {
            id: 'sent',
            header: 'Sent',
            priority: 'secondary',
            cell: (l) => (
                <span className="tabular-nums text-sm">
                    {l.dispatched_base_qty.toLocaleString('en-GB')} of {(l.base_qty - l.cancelled_base_qty).toLocaleString('en-GB')}
                    {l.cancelled_base_qty > 0 && <span className="block text-xs text-muted-foreground">{l.cancelled_base_qty.toLocaleString('en-GB')} cancelled</span>}
                </span>
            ),
        },
        { id: 'unit', header: 'Unit net', align: 'end', priority: 'secondary', cell: (l) => <Money e4={l.unit_price_net_e4} /> },
        { id: 'vat', header: 'VAT', align: 'end', priority: 'secondary', cell: (l) => <span className="tabular-nums">{formatBasisPoints(l.tax_rate_bp)}</span> },
        { id: 'net', header: 'Line net', align: 'end', cell: (l) => <Money minor={l.line_net_minor} /> },
    ];

    return (
        <TradeShell title={`Order ${order.order_number}`}>
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Orders', href: '/trade/orders' }, { label: order.order_number }]}
                title={`Order ${order.order_number}`}
                status={<StatusBadge tone={orderTone(order.status)}>{order.status_label}</StatusBadge>}
                description={
                    <p>
                        Placed {formatUkDateTime(order.placed_at, tz)}
                        {order.placed_by && <> by {order.placed_by}</>}
                        {order.customer_reference && <> · Your reference {order.customer_reference}</>}
                    </p>
                }
                primaryAction={
                    order.status === 'pending_payment' ? (
                        <Button asChild className="h-11">
                            <Link href={`/trade/orders/${order.id}/pay`}>
                                <CreditCard aria-hidden /> Pay for this order
                            </Link>
                        </Button>
                    ) : canReorder ? (
                        <Button className="h-11" disabled={reordering} onClick={reorder}>
                            {reordering ? <Loader2 className="animate-spin" aria-hidden /> : <RotateCcw aria-hidden />} Reorder
                        </Button>
                    ) : undefined
                }
                secondaryActions={order.status === 'pending_payment' && canReorder ? [{ label: 'Reorder', onSelect: reorder }] : []}
            />

            {reorderError && <p role="alert" className="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">{reorderError}</p>}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <section aria-labelledby="items-heading">
                        <h2 id="items-heading" className="mb-3 text-lg font-semibold">
                            Items
                        </h2>
                        <DataTable caption={`Items on order ${order.order_number}`} columns={columns} rows={order.lines} rowKey={(l) => String(l.line_no)} rowLabel={(l) => l.sku_code} cardTitle={(l) => l.name} />
                    </section>

                    <section aria-labelledby="timeline-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="timeline-heading" className="mb-4 text-lg font-semibold">
                            What has happened
                        </h2>
                        {order.timeline.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Nothing yet.</p>
                        ) : (
                            <ol className="relative flex flex-col gap-4 border-l pl-6">
                                {order.timeline.map((e, i) => (
                                    <li key={`${e.at}-${i}`} className="relative">
                                        <span className="absolute -left-[1.85rem] top-0.5 flex size-4 items-center justify-center rounded-full bg-background">
                                            <CheckCircle2 className="size-4 text-primary" aria-hidden />
                                        </span>
                                        <p className="text-sm font-medium">{e.label}</p>
                                        <p className="text-xs tabular-nums text-muted-foreground">{formatUkDateTime(e.at, tz)}</p>
                                    </li>
                                ))}
                            </ol>
                        )}
                        {order.shipments.length > 0 && (
                            <ul className="mt-5 flex flex-col gap-2">
                                {order.shipments.map((s) => (
                                    <li key={s.id} className="flex flex-wrap items-center gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm">
                                        <Truck className="size-4 text-muted-foreground" aria-hidden />
                                        <span className="font-medium">{s.status.replace('_', ' ')}</span>
                                        {s.carrier && <span className="text-muted-foreground">· {s.carrier}</span>}
                                        {s.tracking_number && <span className="font-mono text-xs">· {s.tracking_number}</span>}
                                        {s.dispatched_at && <span className="text-muted-foreground">· sent {formatUkDate(s.dispatched_at, tz)}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <aside className="flex flex-col gap-6">
                    <section aria-labelledby="totals-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="totals-heading" className="mb-2 text-base font-semibold">
                            Totals
                        </h2>
                        <dl className="divide-y">
                            <Row label="Goods net" value={<Money minor={order.totals.subtotal_net_minor} />} />
                            {order.totals.discount_net_minor > 0 && <Row label="Discount (included)" value={<Money minor={order.totals.discount_net_minor} />} />}
                            <Row label="Delivery net" value={<Money minor={order.totals.shipping_net_minor} />} />
                            <Row label="VAT" value={<Money minor={order.totals.tax_minor} />} />
                            <Row label="Total" value={<Money minor={order.totals.total_gross_minor} />} strong />
                        </dl>
                    </section>

                    <section aria-labelledby="payment-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="payment-heading" className="mb-2 text-base font-semibold">
                            Payment and approval
                        </h2>
                        <dl className="divide-y">
                            <Row label="Payment" value={order.payment_method ? (PAYMENT[order.payment_method] ?? order.payment_method) : '—'} />
                            <Row label="Payment status" value={PAYMENT_STATUS[order.payment_status] ?? order.payment_status} />
                            <Row label="Stock" value={order.stock_reserved ? 'Reserved' : 'Not reserved'} />
                        </dl>
                        {order.approvals.length > 0 && (
                            <ul className="mt-3 flex flex-col gap-2 text-sm">
                                {order.approvals.map((a, i) => (
                                    <li key={i} className="rounded-lg bg-muted/50 px-3 py-2">
                                        {a.kind === 'credit_exception' ? 'Credit check' : 'Buyer approval'}: <span className="font-medium">{a.status}</span>
                                        {a.decided_by && <span className="text-muted-foreground"> by {a.decided_by}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {order.delivery_address.length > 0 && (
                        <section aria-labelledby="delivery-heading" className="rounded-xl border bg-background p-5">
                            <h2 id="delivery-heading" className="mb-2 text-base font-semibold">
                                {order.fulfilment_type === 'collection' ? 'Collection' : 'Delivery address'}
                            </h2>
                            <address className="text-sm not-italic leading-relaxed">
                                {order.delivery_address.map((line) => (
                                    <span key={line} className="block">
                                        {line}
                                    </span>
                                ))}
                            </address>
                        </section>
                    )}

                    {order.invoices !== null && (
                        <section aria-labelledby="documents-heading" className="rounded-xl border bg-background p-5">
                            <h2 id="documents-heading" className="mb-2 text-base font-semibold">
                                Invoices and credit notes
                            </h2>
                            {order.invoices.length === 0 && (order.credit_notes ?? []).length === 0 ? (
                                <p className="text-sm text-muted-foreground">None yet. On-account orders are invoiced as they are sent.</p>
                            ) : (
                                <ul className="flex flex-col gap-1 text-sm">
                                    {order.invoices.map((i) => (
                                        <li key={i.id}>
                                            <Link href={`/trade/invoices/${i.id}`} className="inline-flex min-h-11 items-center gap-2 underline underline-offset-4">
                                                <Package className="size-4" aria-hidden /> Invoice {i.number} · <Money minor={i.total_gross_minor} />
                                            </Link>
                                        </li>
                                    ))}
                                    {(order.credit_notes ?? []).map((n) => (
                                        <li key={n.id}>
                                            <Link href={`/trade/credit-notes/${n.id}`} className="inline-flex min-h-11 items-center gap-2 underline underline-offset-4">
                                                Credit note {n.number} · <Money minor={n.total_gross_minor} />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </aside>
            </div>
        </TradeShell>
    );
}
