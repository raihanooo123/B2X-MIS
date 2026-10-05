/**
 * Collections counter (05.6 §7A.6). Today's and overdue bookings; search
 * by order number, name, email or phone. For the chosen order, in the
 * spec's order:
 *
 *   1. identify the collector — the customer, or someone they name; a
 *      guest shows the order number and the email it was placed with;
 *   2. record the cash (pay-at-collection orders only): the amount due,
 *      exactly, confirmed as received — the receipt or invoice is issued
 *      at once and its number shown;
 *   3. hand over — refused by the server while the order is unpaid.
 *
 * A wrongly keyed cash payment is voided, before handover, by accounts.
 * Warehouse conditions: 48 px targets, identifiers in a monospaced face.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Banknote, PackageCheck, Search, Store } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FIELD, MONO, Notice, TARGET, formatTime } from '@/components/warehouse/scan';
import { WarehouseNavigation } from '@/components/warehouse/WarehouseNavigation';
import { formatMinor } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface BookingRow {
    order_id: string | null;
    order_number: string | null;
    customer: string | null;
    slot: string | null;
    overdue: boolean;
    status: string;
    payment_method: string | null;
    payment_status: string | null;
    amount_due_minor: number;
}

interface OrderDetail {
    id: string;
    order_number: string;
    status: string;
    customer: string;
    email: string | null;
    phone: string | null;
    is_guest: boolean;
    is_trade: boolean;
    payment_method: string | null;
    payment_method_label: string | null;
    payment_status: string;
    pays_cash: boolean;
    amount_due_minor: number;
    booking: { status: string; slot: string | null; location: string | null; payment_due_by: string | null; collector_name: string | null; collected_at: string | null } | null;
    lines: { line_no: number; name: string; sku_code: string; pack_label: string; pack_qty: number; base_qty: number; cancelled_base_qty: number; dispatched_base_qty: number; picked_base_qty: number }[];
    shipment: { id: string; status: string; pick_url: string } | null;
    cash_payments: { id: string; amount_minor: number; status: string; captured_at: string | null; recorded_by: string | null }[];
    document: { label: string; number: string; status: string } | null;
    urls: { record_cash: string; handover: string };
}

interface CollectionsProps {
    search: string;
    bookings: BookingRow[];
    order: OrderDetail | null;
    can: { serve: boolean; void_cash: boolean };
}

export default function Collections({ search, bookings, order, can }: CollectionsProps) {
    const { flash } = usePage<SharedProps>().props;
    const [query, setQuery] = useState(search);

    const find = (e: FormEvent) => {
        e.preventDefault();
        router.get('/warehouse/collections', query.trim() === '' ? {} : { q: query.trim() }, { preserveState: false });
    };

    return (
        <div className="min-h-screen bg-slate-100 pb-24 text-lg text-slate-900 antialiased">
            <Head title="Collections" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div className="flex items-center gap-3">
                        <Store className="size-7 text-slate-700" aria-hidden />
                        <h1 className="text-2xl font-bold">Collections</h1>
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <WarehouseNavigation />
            <main className="mx-auto max-w-5xl space-y-6 px-4 pt-6">
                {flash.status && <Notice tone="ok">{flash.status}</Notice>}

                <form onSubmit={find} className="flex gap-2" role="search">
                    <label htmlFor="collections-search" className="sr-only">
                        Order number, name, email or phone
                    </label>
                    <Input id="collections-search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Order number, name, email or phone" className={cn(FIELD, 'bg-white')} autoFocus />
                    <Button type="submit" className={TARGET}>
                        <Search aria-hidden /> Find
                    </Button>
                </form>

                {order !== null ? <OrderPanel order={order} can={can} /> : <BookingList bookings={bookings} searching={search !== ''} />}
            </main>
        </div>
    );
}

function BookingList({ bookings, searching }: { bookings: BookingRow[]; searching: boolean }) {
    if (bookings.length === 0) {
        return <Notice tone="info">{searching ? 'No collection matches that search.' : 'No collections are due today.'}</Notice>;
    }

    return (
        <section className="space-y-2" aria-label={searching ? 'Matching collections' : "Today's and overdue collections"}>
            <h2 className="text-base font-semibold text-slate-600">{searching ? 'Matching collections' : "Today's and overdue collections"}</h2>
            <ul className="space-y-2">
                {bookings.map((b) => (
                    <li key={b.order_id ?? b.order_number}>
                        <Link
                            href={`/warehouse/collections?order=${b.order_id}`}
                            className="flex min-h-12 flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-4 py-3 hover:border-slate-500"
                        >
                            <span className="min-w-0">
                                <span className={cn(MONO, 'font-semibold')}>{b.order_number}</span>
                                <span className="ml-3 text-base">{b.customer}</span>
                                <span className="block text-sm text-slate-600">
                                    {b.slot}
                                    {b.overdue && <span className="ml-2 font-semibold text-amber-700">Overdue</span>}
                                </span>
                            </span>
                            <span className="text-right text-sm">
                                {b.payment_method === 'cash_at_collection' && b.payment_status !== 'paid' ? (
                                    <span className="font-semibold">Take {formatMinor(b.amount_due_minor)} cash</span>
                                ) : (
                                    <span className="text-emerald-700">{b.payment_status === 'paid' ? 'Paid' : (b.payment_status ?? '')}</span>
                                )}
                                {b.status !== 'booked' && <span className="block text-slate-600">{b.status.replace('_', ' ')}</span>}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function OrderPanel({ order, can }: { order: OrderDetail; can: CollectionsProps['can'] }) {
    const booking = order.booking;
    const live = booking?.status === 'booked';
    const paid = order.payment_status === 'paid' || order.payment_status === 'on_account';
    const fullyPicked = order.lines.every((l) => l.picked_base_qty + l.cancelled_base_qty + l.dispatched_base_qty >= l.base_qty);

    return (
        <div className="space-y-6">
            <Link href="/warehouse/collections" className="inline-flex min-h-12 items-center text-base underline">
                ← All collections
            </Link>

            <section className="space-y-3 rounded-xl border border-slate-300 bg-white p-5">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 className={cn(MONO, 'text-2xl font-bold')}>{order.order_number}</h2>
                    <span className="text-base text-slate-600">{booking?.slot}</span>
                </div>
                <dl className="grid gap-x-6 gap-y-2 text-base sm:grid-cols-2">
                    <Fact label="Customer" value={order.customer} />
                    <Fact label={order.is_guest ? 'Order email (guest — check it)' : 'Email'} value={order.email} />
                    <Fact label="Phone" value={order.phone} />
                    <Fact label="Payment" value={order.payment_method_label} />
                    <Fact label="Collection" value={booking === null ? null : booking.status === 'collected' && booking.collected_at ? `Collected ${formatTime(booking.collected_at)}${booking.collector_name ? ` by ${booking.collector_name}` : ''}` : booking.status.replace('_', ' ')} />
                    {order.pays_cash && booking?.payment_due_by && live && <Fact label="Pay by" value={formatTime(booking.payment_due_by)} />}
                </dl>
            </section>

            <section className="space-y-2 rounded-xl border border-slate-300 bg-white p-5" aria-label="Lines">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-lg font-semibold">Goods</h3>
                    {order.shipment && live && (
                        <Link href={order.shipment.pick_url} className="text-base underline">
                            Pick list ({order.shipment.status})
                        </Link>
                    )}
                </div>
                <ul className="divide-y divide-slate-200">
                    {order.lines.map((l) => {
                        const due = l.base_qty - l.cancelled_base_qty;

                        return (
                            <li key={l.line_no} className="flex flex-wrap justify-between gap-3 py-2 text-base">
                                <span>
                                    {l.pack_qty} × {l.name} <span className="text-slate-600">({l.pack_label})</span>
                                    <span className={cn(MONO, 'block text-sm text-slate-600')}>{l.sku_code}</span>
                                </span>
                                <span className="text-sm">{l.dispatched_base_qty >= due ? 'Handed over' : l.picked_base_qty >= due ? 'Picked' : `Picked ${l.picked_base_qty} of ${due}`}</span>
                            </li>
                        );
                    })}
                </ul>
            </section>

            {order.pays_cash && <CashPanel order={order} can={can} live={live} />}

            {live && can.serve && <HandoverPanel order={order} paid={paid} fullyPicked={fullyPicked} />}
        </div>
    );
}

function CashPanel({ order, can, live }: { order: OrderDetail; can: CollectionsProps['can']; live: boolean }) {
    const errors = usePage<SharedProps>().props.errors as Record<string, string | undefined>;
    const form = useForm({ amount_minor: order.amount_due_minor, received: false });
    const captured = order.cash_payments.find((p) => p.status === 'captured') ?? null;

    const record = (e: FormEvent) => {
        e.preventDefault();
        form.post(order.urls.record_cash, { preserveScroll: true });
    };

    return (
        <section className="space-y-4 rounded-xl border-2 border-slate-400 bg-white p-5" aria-label="Cash">
            <h3 className="flex items-center gap-2 text-lg font-semibold">
                <Banknote className="size-5" aria-hidden /> Cash
            </h3>
            {errors.cash && <Notice tone="error">{errors.cash}</Notice>}
            {errors.void && <Notice tone="error">{errors.void}</Notice>}

            {captured === null ? (
                live && can.serve ? (
                    <form onSubmit={record} className="space-y-3">
                        <p className="text-2xl font-bold tabular-nums">Take {formatMinor(order.amount_due_minor)} in cash</p>
                        <p className="text-sm text-slate-600">The exact amount: no part-payments. Give change from the till drawer.</p>
                        <label className="flex min-h-12 items-center gap-3 text-base">
                            <input type="checkbox" className="size-6" checked={form.data.received} onChange={(e) => form.setData('received', e.target.checked)} />
                            {formatMinor(order.amount_due_minor)} received in cash
                        </label>
                        <Button type="submit" className={cn(TARGET, 'w-full sm:w-auto')} disabled={!form.data.received || form.processing}>
                            Record cash payment
                        </Button>
                    </form>
                ) : (
                    <p className="text-base">{live ? 'Waiting for payment at the counter.' : 'No cash was taken for this order.'}</p>
                )
            ) : (
                <div className="space-y-1 text-base">
                    <p>
                        <span className="font-semibold">{formatMinor(captured.amount_minor)}</span> received{captured.captured_at ? ` at ${formatTime(captured.captured_at)}` : ''}
                        {captured.recorded_by ? ` by ${captured.recorded_by}` : ''}.
                    </p>
                    {order.document ? (
                        <p>
                            {order.document.label} <span className={MONO}>{order.document.number}</span> issued and emailed to the customer. Its PDF is in the admin panel once rendered.
                        </p>
                    ) : (
                        <p className="text-amber-800">The receipt has not been issued yet; accounts will issue it.</p>
                    )}
                </div>
            )}

            {captured !== null && live && can.void_cash && <VoidForm paymentId={captured.id} />}

            {order.cash_payments.filter((p) => p.status === 'voided').length > 0 && (
                <p className="text-sm text-slate-600">
                    Voided: {order.cash_payments.filter((p) => p.status === 'voided').map((p) => formatMinor(p.amount_minor)).join(', ')}
                </p>
            )}
        </section>
    );
}

/** 05.6 §7A.6: accounts only, before handover, with a reason. */
function VoidForm({ paymentId }: { paymentId: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    if (!open) {
        return (
            <Button type="button" variant="outline" className={TARGET} onClick={() => setOpen(true)}>
                Void this cash payment
            </Button>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/warehouse/collections/cash/${paymentId}/void`, { preserveScroll: true });
            }}
            className="space-y-2"
        >
            <label htmlFor="void-reason" className="text-base font-medium">
                Why is it wrong?
            </label>
            <Input id="void-reason" value={form.data.reason} maxLength={500} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} />
            {form.errors.reason && <p className="text-sm text-red-700">{form.errors.reason}</p>}
            <div className="flex gap-2">
                <Button type="submit" variant="destructive" className={TARGET} disabled={form.data.reason.trim() === '' || form.processing}>
                    Void payment
                </Button>
                <Button type="button" variant="ghost" className={TARGET} onClick={() => setOpen(false)}>
                    Keep it
                </Button>
            </div>
        </form>
    );
}

function HandoverPanel({ order, paid, fullyPicked }: { order: OrderDetail; paid: boolean; fullyPicked: boolean }) {
    const errors = usePage<SharedProps>().props.errors as Record<string, string | undefined>;
    const form = useForm({ collector_name: '', identity_checked: false });
    const ready = paid && fullyPicked;

    return (
        <section className="space-y-3 rounded-xl border border-slate-300 bg-white p-5" aria-label="Handover">
            <h3 className="flex items-center gap-2 text-lg font-semibold">
                <PackageCheck className="size-5" aria-hidden /> Hand over
            </h3>
            {errors.handover && <Notice tone="error">{errors.handover}</Notice>}
            {!paid && <Notice tone="info">{order.pays_cash ? 'Record the cash before handing over.' : 'This order is not paid yet, so it cannot be handed over.'}</Notice>}
            {paid && !fullyPicked && <Notice tone="info">Pick every line before handing over.</Notice>}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(order.urls.handover, { preserveScroll: true });
                }}
                className="space-y-3"
            >
                <label htmlFor="collector-name" className="block text-base font-medium">
                    Collected by (if not the customer)
                </label>
                <Input id="collector-name" value={form.data.collector_name} maxLength={191} onChange={(e) => form.setData('collector_name', e.target.value)} className={FIELD} />
                <label className="flex min-h-12 items-center gap-3 text-base">
                    <input type="checkbox" className="size-6" checked={form.data.identity_checked} onChange={(e) => form.setData('identity_checked', e.target.checked)} />
                    {order.is_guest ? 'Order number and email checked' : 'Collector identified'}
                </label>
                <Button type="submit" className={cn(TARGET, 'w-full sm:w-auto')} disabled={!ready || !form.data.identity_checked || form.processing}>
                    Hand over order
                </Button>
            </form>
        </section>
    );
}

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-sm text-slate-600">{label}</dt>
            <dd>{value ?? '—'}</dd>
        </div>
    );
}
