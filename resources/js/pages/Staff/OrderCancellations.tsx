import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Search } from 'lucide-react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types/shared';

interface Line {
    line_no: number;
    name: string;
    sku_code: string;
    pack_label: string;
    max_pack_qty: number;
    kept_base_qty: number;
    pack_base_units: number;
    applied_break_qty: number | null;
}

interface OrderView {
    id: string;
    order_number: string;
    status: string;
    undispatched_cancellation: { trade: boolean; lines: Line[] } | null;
}

interface Props {
    order: OrderView | null;
    store_url: string | null;
    search: string;
}

/** 05.10 §2: record the customer's instruction, not a new order or a re-price. */
export default function OrderCancellations({ order, store_url: storeUrl, search }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [number, setNumber] = useState(search);
    const [token, setToken] = useState(() => crypto.randomUUID());
    const form = useForm<{
        lines: { line_no: number; pack_qty: number }[];
        reason_detail: string;
        customer_notified_at: string;
        reason_code: string | null;
    }>({
        lines: order?.undispatched_cancellation?.lines.map((line) => ({ line_no: line.line_no, pack_qty: 0 })) ?? [],
        reason_detail: '',
        customer_notified_at: '',
        reason_code: null,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const selected = form.data.lines.some((line) => line.pack_qty > 0);
    const belowBreak = order?.undispatched_cancellation?.trade && order.undispatched_cancellation.lines.some((line) => {
        const packs = form.data.lines.find((item) => item.line_no === line.line_no)?.pack_qty ?? 0;
        const kept = line.kept_base_qty - packs * line.pack_base_units;

        return line.applied_break_qty !== null && kept > 0 && kept < line.applied_break_qty;
    });

    const find = (event: FormEvent) => {
        event.preventDefault();
        router.get('/staff/order-cancellations', { order: number.trim() });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!storeUrl) return;

        form.transform((data) => ({ ...data, reason_code: belowBreak ? 'below_break_override' : null }));
        form.post(storeUrl, {
            preserveScroll: true,
            headers: { 'Idempotency-Key': token },
            onSuccess: () => {
                form.reset();
                setToken(crypto.randomUUID());
            },
        });
    };

    return (
        <>
            <Head title="Order cancellations" />
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-8">
                <header className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold">Order cancellations</h1>
                    <AccountMenu />
                </header>

                <form onSubmit={find} className="flex gap-2">
                    <label htmlFor="order-number" className="sr-only">Order number</label>
                    <input id="order-number" value={number} onChange={(event) => setNumber(event.target.value)} placeholder="Order number" className="h-12 min-w-0 flex-1 rounded-md border border-input bg-background px-3" />
                    <Button type="submit" className="h-12"><Search className="mr-2 size-4" />Find order</Button>
                </form>

                {search && !order && <p role="status">No order found for {search}.</p>}
                {flash?.status && <p role="status" className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-emerald-900">{flash.status}</p>}

                {order && (
                    <section className="space-y-4">
                        <div>
                            <h2 className="font-semibold">{order.order_number}</h2>
                            <p className="text-sm text-muted-foreground">Status: {order.status}</p>
                        </div>
                        {!order.undispatched_cancellation || !storeUrl ? (
                            <p>There are no unpacked, undispatched packs available to cancel.</p>
                        ) : (
                            <form onSubmit={submit} className="space-y-4">
                                {order.undispatched_cancellation.lines.map((line, index) => (
                                    <div key={line.line_no} className="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                                        <div>
                                            <label htmlFor={`staff-cancel-${line.line_no}`} className="font-medium">{line.name}</label>
                                            <p className="text-sm text-muted-foreground">{line.sku_code} · {line.pack_label} · {line.max_pack_qty} pack(s) available</p>
                                        </div>
                                        <input
                                            id={`staff-cancel-${line.line_no}`}
                                            type="number" min={0} max={line.max_pack_qty} step={1}
                                            value={form.data.lines.find((item) => item.line_no === line.line_no)?.pack_qty ?? 0}
                                            onChange={(event) => form.setData('lines', form.data.lines.map((item) => item.line_no === line.line_no ? { ...item, pack_qty: Number(event.target.value) } : item))}
                                            className="h-12 w-24 rounded-md border border-input bg-background px-2"
                                        />
                                        {errors[`lines.${index}.pack_qty`] && <p role="alert" className="w-full text-sm text-red-700">{errors[`lines.${index}.pack_qty`]}</p>}
                                    </div>
                                ))}
                                {belowBreak && <p className="text-sm text-amber-800">This reduces a trade line below its original price break. Your reason will be audited as an override; kept items will not be re-priced.</p>}
                                <div>
                                    <label htmlFor="notified-at" className="mb-1 block font-medium">Customer notified us at (UK time)</label>
                                    <input id="notified-at" type="datetime-local" value={form.data.customer_notified_at} onChange={(event) => form.setData('customer_notified_at', event.target.value)} className="h-12 rounded-md border border-input bg-background px-3" />
                                    {errors.customer_notified_at && <p role="alert" className="text-sm text-red-700">{errors.customer_notified_at}</p>}
                                </div>
                                <div>
                                    <label htmlFor="staff-reason" className="mb-1 block font-medium">Customer instruction and reason</label>
                                    <textarea id="staff-reason" value={form.data.reason_detail} onChange={(event) => form.setData('reason_detail', event.target.value)} maxLength={500} rows={3} className="w-full rounded-md border border-input bg-background p-3" />
                                    {errors.reason_detail && <p role="alert" className="text-sm text-red-700">{errors.reason_detail}</p>}
                                </div>
                                {errors.lines && <p role="alert" className="text-sm text-red-700">{errors.lines}</p>}
                                <Button type="submit" disabled={!selected || !form.data.customer_notified_at || !form.data.reason_detail.trim() || form.processing}>Record cancellation</Button>
                            </form>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}
