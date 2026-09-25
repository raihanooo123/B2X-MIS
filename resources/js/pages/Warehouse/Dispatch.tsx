/**
 * Dispatch (05.5 §7, §9). Picked shipments wait here. The screen shows
 * exactly what this shipment carries — in packs, with batches and serials
 * — and what the order will still be owed after it, so a partial dispatch
 * is visibly partial before it is confirmed (05.5 §7.2).
 *
 * Confirming is one request: the server dispatches atomically (04 §7.2),
 * tells the customer what shipped and what remains, and invoices
 * on-account orders. The request carries an Idempotency-Key (06 §6):
 * a double-tap or a retry after a dropped response dispatches once.
 *
 * Scan-everywhere: scan an order number to pick its shipment from the
 * queue; the tracking field takes a scanned courier label. 48 px targets,
 * keyboard-complete — Enter moves between fields, Ctrl+Enter dispatches.
 */
import { Head, Link } from '@inertiajs/react';
import { Check, Truck } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent as ReactKeyboardEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FIELD, MONO, Notice, ScanBar, TARGET, describeError, formatTime, packsText, useScanFocus } from '@/components/warehouse/scan';
import { ApiError } from '@/lib/api/client';
import { useDispatchShipment, usePickList, type DispatchInput, type PickListData, type Ulid } from '@/lib/api/fulfilment';
import { cn } from '@/lib/utils';

interface QueueEntry {
    id: Ulid;
    order_number: string;
    status: string;
    fulfilment_type: string;
    location_code: string | null;
    picked_at: string | null;
}

interface DispatchProps {
    shipment: PickListData | null;
    queue: QueueEntry[];
}

function dispatchUrl(id: Ulid | null): string {
    return id === null ? '/warehouse/dispatch' : `/warehouse/dispatch?shipment=${id}`;
}

/** Kilograms typed on a keypad → whole grams, by string arithmetic: "12.5" → 12500. */
function kgToGrams(raw: string): number | null {
    const match = /^(\d{1,6})(?:\.(\d{1,3}))?$/.exec(raw.trim());
    if (match === null) {
        return null;
    }

    return Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
}

export default function Dispatch(props: DispatchProps) {
    const [shipmentId, setShipmentId] = useState<Ulid | null>(props.shipment?.shipment.id ?? null);

    const go = (id: Ulid | null) => {
        setShipmentId(id);
        window.history.replaceState(window.history.state, '', dispatchUrl(id));
    };

    return (
        <div className="min-h-screen bg-slate-100 pb-24 text-lg text-slate-900 antialiased">
            <Head title="Dispatch" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-3">
                        <Truck className="size-7 text-slate-700" aria-hidden />
                        <h1 className="text-2xl font-bold">Dispatch</h1>
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <main className="mx-auto max-w-5xl space-y-6 px-4 pt-6">
                {shipmentId === null ? (
                    <QueuePanel queue={props.queue} onChoose={go} />
                ) : (
                    <ShipmentPanel key={shipmentId} shipmentId={shipmentId} initial={props.shipment} onLeave={() => go(null)} />
                )}
            </main>
        </div>
    );
}

function QueuePanel({ queue, onChoose }: { queue: QueueEntry[]; onChoose: (id: Ulid) => void }) {
    const scanRef = useRef<HTMLInputElement>(null);
    const [message, setMessage] = useState<string | null>(null);
    useScanFocus(scanRef, true);

    const onScan = (code: string) => {
        const entry = queue.find((q) => q.order_number === code);
        if (entry) {
            onChoose(entry.id);
        } else {
            setMessage(`${code} has no picked shipment waiting. Finish picking it first.`);
        }
    };

    return (
        <>
            <ScanBar id="dispatch-scan" inputRef={scanRef} label="Scan or type an order number" busy={false} onScan={onScan} />
            {message && <Notice tone="error">{message}</Notice>}
            <section aria-labelledby="dispatch-queue">
                <h2 id="dispatch-queue" className="mb-2 text-xl font-semibold">
                    Picked, ready to go
                </h2>
                {queue.length === 0 ? (
                    <p className="text-base text-slate-600">Nothing waiting.</p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {queue.map((q) => (
                            <li key={q.id}>
                                <Button type="button" variant="outline" className="h-auto min-h-12 w-full justify-between bg-white px-4 py-3 text-left text-base" onClick={() => onChoose(q.id)}>
                                    <span>
                                        <span className={cn('block font-semibold', MONO)}>{q.order_number}</span>
                                        <span className="block text-sm text-slate-600">
                                            {q.fulfilment_type} · {q.location_code}
                                            {q.picked_at && <> · picked {formatTime(q.picked_at)}</>}
                                        </span>
                                    </span>
                                    <span aria-hidden>Dispatch</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

function ShipmentPanel({ shipmentId, initial, onLeave }: { shipmentId: Ulid; initial: PickListData | null; onLeave: () => void }) {
    const query = usePickList(shipmentId, initial);
    const data = query.data;

    if (query.isPending) {
        return <Notice tone="info">Loading shipment…</Notice>;
    }
    if (data === undefined) {
        return (
            <>
                <Notice tone="error">{describeError(query.error)}</Notice>
                <Button type="button" variant="outline" className={TARGET} onClick={onLeave}>
                    Back to the queue
                </Button>
            </>
        );
    }

    const dispatched = data.shipment.status === 'dispatched';
    const onThis = data.order_lines.filter((l) => l.on_this_shipment_base_qty > 0);
    const remaining = data.order_lines
        .map((l) => ({ ...l, after: l.ordered_base_qty - l.dispatched_base_qty - (dispatched ? 0 : l.on_this_shipment_base_qty) }))
        .filter((l) => l.after > 0);

    return (
        <>
            <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white p-4">
                <div>
                    <p className="text-sm uppercase tracking-wide text-slate-600">
                        {data.shipment.fulfilment_type} · {data.shipment.location_code}
                    </p>
                    <p className={cn('text-2xl font-bold', MONO)}>{data.order.order_number}</p>
                    <p className="text-base text-slate-600">
                        {data.order.customer ?? 'Public customer'}
                        {data.order.customer_reference && <> · ref {data.order.customer_reference}</>}
                    </p>
                </div>
                <div className="flex flex-col items-end gap-2">
                    <span className={cn('rounded-full px-3 py-1 text-base font-semibold', remaining.length === 0 ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900')}>
                        {remaining.length === 0 ? 'Completes the order' : 'Partial — more to follow'}
                    </span>
                    <Button type="button" variant="ghost" className={cn(TARGET, 'px-4')} onClick={onLeave}>
                        {dispatched ? 'Next shipment' : 'Back to the queue'}
                    </Button>
                </div>
            </section>

            <section aria-labelledby="contents-heading" className="rounded-xl border border-slate-300 bg-white p-4">
                <h2 id="contents-heading" className="text-xl font-semibold">
                    {dispatched ? 'Shipped' : 'On this shipment'}
                </h2>
                <ul className="mt-2 divide-y divide-slate-200">
                    {onThis.map((l) => {
                        const picks = data.lines.filter((p) => p.line_no === l.line_no);

                        return (
                            <li key={l.line_no} className="py-2 text-base">
                                <p>
                                    <span className={cn('font-semibold', MONO)}>{l.sku_code}</span> {l.name} — <strong>{packsText(l.on_this_shipment_base_qty, l.pack_label, l.pack_base_units)}</strong>
                                </p>
                                {picks.map((p) => (
                                    <p key={`${p.line_no}:${p.batch_code ?? ''}`} className="text-sm text-slate-700">
                                        {p.batch_code && (
                                            <>
                                                Batch <span className={MONO}>{p.batch_code}</span>
                                                {p.expires_on && <> exp {p.expires_on}</>} ·{' '}
                                            </>
                                        )}
                                        {p.base_qty} units
                                        {p.serials.length > 0 && <> · serials <span className={MONO}>{p.serials.map((s) => s.serial_number).join(', ')}</span></>}
                                    </p>
                                ))}
                            </li>
                        );
                    })}
                    {onThis.length === 0 && <li className="py-2 text-base text-slate-600">Nothing picked on this shipment.</li>}
                </ul>
            </section>

            {remaining.length > 0 && (
                <section aria-labelledby="remaining-heading" className="rounded-xl border-2 border-amber-500 bg-amber-50 p-4">
                    <h2 id="remaining-heading" className="text-xl font-semibold">
                        Still owed after this shipment
                    </h2>
                    <p className="text-sm text-amber-950">The customer is told what shipped and what remains.</p>
                    <ul className="mt-2 space-y-1 text-base">
                        {remaining.map((l) => (
                            <li key={l.line_no}>
                                <span className={cn('font-semibold', MONO)}>{l.sku_code}</span> {l.name} — {packsText(l.after, l.pack_label, l.pack_base_units)}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {dispatched ? (
                <Notice tone="ok">
                    <span className="flex items-center gap-2 font-semibold">
                        <Check className="size-5" aria-hidden /> Dispatched {data.shipment.dispatched_at ? formatTime(data.shipment.dispatched_at) : ''}
                        {data.shipment.carrier && <> with {data.shipment.carrier}</>}
                        {data.shipment.tracking_number && (
                            <>
                                {' '}
                                · tracking <span className={MONO}>{data.shipment.tracking_number}</span>
                            </>
                        )}
                        .
                    </span>
                </Notice>
            ) : data.complete ? (
                <DispatchForm shipmentId={shipmentId} data={data} />
            ) : (
                <Notice tone="error">
                    Not everything is picked yet. Dispatch needs every line picked or reported short.{' '}
                    <Link href={`/warehouse/pick-list?shipment=${shipmentId}`} className="font-semibold underline">
                        Back to picking
                    </Link>
                </Notice>
            )}
        </>
    );
}

function DispatchForm({ shipmentId, data }: { shipmentId: Ulid; data: PickListData }) {
    const dispatch = useDispatchShipment(shipmentId);
    const formRef = useRef<HTMLFormElement>(null);
    const idempotency = useRef<{ fingerprint: string; key: string } | null>(null);
    const collection = data.shipment.fulfilment_type === 'collection';
    const [carrier, setCarrier] = useState('');
    const [tracking, setTracking] = useState('');
    const [parcels, setParcels] = useState('1');
    const [weight, setWeight] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        formRef.current?.querySelector<HTMLElement>('[data-dispatch-field]')?.focus();
    }, []);

    const submit = () => {
        const grams = weight.trim() === '' ? null : kgToGrams(weight);
        const parcelCount = parcels.trim() === '' ? null : /^\d{1,5}$/.test(parcels.trim()) ? Number(parcels.trim()) : NaN;

        if (!collection && carrier.trim() === '') {
            setError('Record the carrier.');
            return;
        }
        if (weight.trim() !== '' && grams === null) {
            setError('Weight is kilograms, up to three decimal places.');
            return;
        }
        if (Number.isNaN(parcelCount)) {
            setError('Parcels is a whole number.');
            return;
        }

        const input: DispatchInput = {
            carrier: carrier.trim() || null,
            tracking_number: tracking.trim() || null,
            parcel_count: parcelCount,
            total_weight_g: grams,
            note: note.trim() || null,
        };

        // Same body, same key: a retry dispatches once (06 §6).
        const fingerprint = JSON.stringify(input);
        if (idempotency.current?.fingerprint !== fingerprint) {
            idempotency.current = { fingerprint, key: crypto.randomUUID() };
        }

        setError(null);
        dispatch.mutate(
            { input, idempotencyKey: idempotency.current.key },
            { onError: (e) => setError(e instanceof ApiError ? e.message : describeError(e)) },
        );
    };

    /** Enter moves on (a scanned tracking label ends in Enter); Ctrl/⌘+Enter dispatches. */
    const onKeyDown = (e: ReactKeyboardEvent<HTMLFormElement>) => {
        if (e.key !== 'Enter' || e.target instanceof HTMLButtonElement) {
            return; // Enter on the button is its own submit.
        }
        e.preventDefault();
        if (e.ctrlKey || e.metaKey) {
            submit();
            return;
        }
        const fields = Array.from(formRef.current?.querySelectorAll<HTMLElement>('[data-dispatch-field]') ?? []);
        const next = fields[fields.indexOf(e.target as HTMLElement) + 1];
        (next ?? formRef.current?.querySelector<HTMLButtonElement>('#dispatch-confirm'))?.focus();
    };

    return (
        <form
            ref={formRef}
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                submit();
            }}
            onKeyDown={onKeyDown}
            className="space-y-4 rounded-xl border-2 border-slate-800 bg-white p-5"
            aria-labelledby="dispatch-heading"
            noValidate
        >
            <h2 id="dispatch-heading" className="text-2xl font-bold">
                {collection ? 'Hand over for collection' : 'Hand to the carrier'}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
                {!collection && (
                    <>
                        <div>
                            <label htmlFor="dispatch-carrier" className="block text-base font-semibold">
                                Carrier <span className="font-normal text-red-700">required</span>
                            </label>
                            <Input id="dispatch-carrier" data-dispatch-field value={carrier} onChange={(e) => setCarrier(e.target.value)} autoComplete="off" className={cn(FIELD, 'mt-1')} />
                        </div>
                        <div>
                            <label htmlFor="dispatch-tracking" className="block text-base font-semibold">
                                Tracking number <span className="font-normal text-slate-600">scan the label</span>
                            </label>
                            <Input id="dispatch-tracking" data-dispatch-field value={tracking} onChange={(e) => setTracking(e.target.value)} autoComplete="off" spellCheck={false} className={cn(FIELD, 'mt-1', MONO)} />
                        </div>
                    </>
                )}
                <div>
                    <label htmlFor="dispatch-parcels" className="block text-base font-semibold">
                        Parcels
                    </label>
                    <Input id="dispatch-parcels" data-dispatch-field value={parcels} onChange={(e) => setParcels(e.target.value.replace(/\D/g, ''))} inputMode="numeric" className={cn(FIELD, 'mt-1 w-32 tabular-nums')} />
                </div>
                <div>
                    <label htmlFor="dispatch-weight" className="block text-base font-semibold">
                        Total weight, kg <span className="font-normal text-slate-600">optional</span>
                    </label>
                    <Input id="dispatch-weight" data-dispatch-field value={weight} onChange={(e) => setWeight(e.target.value)} inputMode="decimal" className={cn(FIELD, 'mt-1 w-40 tabular-nums')} />
                </div>
                <div className="sm:col-span-2">
                    <label htmlFor="dispatch-note" className="block text-base font-semibold">
                        {collection ? 'Collected by (name, ID checked)' : 'Note'} {collection ? null : <span className="font-normal text-slate-600">optional</span>}
                    </label>
                    <Input id="dispatch-note" data-dispatch-field value={note} onChange={(e) => setNote(e.target.value)} autoComplete="off" className={cn(FIELD, 'mt-1')} />
                </div>
            </div>
            {error && <Notice tone="error">{error}</Notice>}
            <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                <Button id="dispatch-confirm" type="submit" className={cn(TARGET, 'px-8 text-lg')} disabled={dispatch.isPending}>
                    <Truck aria-hidden /> {dispatch.isPending ? 'Dispatching…' : 'Confirm dispatch'}
                </Button>
                <p className="text-sm text-slate-600">Enter moves to the next field · Ctrl+Enter dispatches</p>
            </div>
        </form>
    );
}
