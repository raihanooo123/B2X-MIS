/**
 * Picking (05.5 §5, §9). Start from the queue or by scanning an order
 * number; the pick list is per shipment and in walk order — bin walk
 * sequence, then SKU code (05.5 §5.1).
 *
 * Each line shows what to take and from where: the suggested bin, the
 * quantity in packs ("3 × Outer of 12"), the exact batch and expiry, and
 * for serial-tracked stock the reserved serials (05.5 §5.2).
 *
 *   - **Scan everywhere.** The scan bar takes a serial (picks it), a SKU or
 *     case barcode (jumps to its line), or — before a shipment is open —
 *     an order number. A printable key pressed with nothing focused lands
 *     in it. A serial that is not reserved for this order is **blocked,
 *     not warned**: the server refuses it and the screen says why.
 *   - **Keyboard-complete.** Tab reaches every action; each line's
 *     short-pick and batch-substitution forms submit on Enter and cancel
 *     on Esc. 48 px targets throughout, no hover-only interaction.
 *   - A short pick is entered as the picker counts: packs plus loose units.
 *     The server records it, writes the adjustment, and re-plans the line;
 *     the screen reports what was re-planned and what is backordered.
 */
import { Head, Link } from '@inertiajs/react';
import { Check, ClipboardList, Package, Replace, TriangleAlert, Truck } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { WarehouseNavigation } from '@/components/warehouse/WarehouseNavigation';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FIELD, MONO, Notice, ScanBar, TARGET, describeError, formatTime, packsText, useScanFocus, type NoticeTone } from '@/components/warehouse/scan';
import {
    lineForCode,
    lineForSerial,
    useConfirmPick,
    useOpenShipment,
    usePickList,
    useScanSerial,
    useShortPick,
    useSubstituteBatch,
    type PickLineData,
    type PickListData,
    type Ulid,
} from '@/lib/api/fulfilment';
import { cn } from '@/lib/utils';

interface QueueEntry {
    order_number: string;
    location_code: string;
    lines: number;
    base_qty: number;
    fulfilment_type: string;
    open_shipment_id: Ulid | null;
    placed_at: string | null;
}

interface PickListProps {
    pick_list: PickListData | null;
    queue: QueueEntry[];
    short_pick_reasons: { value: string; label: string }[];
}

function pickListUrl(id: Ulid | null): string {
    return id === null ? '/warehouse/pick-list' : `/warehouse/pick-list?shipment=${id}`;
}

function lineKey(line: { line_no: number; batch_code: string | null }): string {
    return `${line.line_no}:${line.batch_code ?? ''}`;
}

export default function PickList(props: PickListProps) {
    const [shipmentId, setShipmentId] = useState<Ulid | null>(props.pick_list?.shipment.id ?? null);

    const go = (id: Ulid | null) => {
        setShipmentId(id);
        window.history.replaceState(window.history.state, '', pickListUrl(id));
    };

    return (
        <div className="min-h-screen bg-slate-100 pb-24 text-lg text-slate-900 antialiased">
            <Head title="Picking" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div className="flex items-center gap-3">
                        <ClipboardList className="size-7 text-slate-700" aria-hidden />
                        <h1 className="text-2xl font-bold">Picking</h1>
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <WarehouseNavigation />
            <main className="mx-auto max-w-5xl space-y-6 px-4 pt-6">
                {shipmentId === null ? (
                    <StartPanel queue={props.queue} onOpened={go} />
                ) : (
                    <ShipmentPanel key={shipmentId} shipmentId={shipmentId} initial={props.pick_list} reasons={props.short_pick_reasons} onLeave={() => go(null)} />
                )}
            </main>
        </div>
    );
}

// --- Start: queue, or scan an order number -------------------------------

function StartPanel({ queue, onOpened }: { queue: QueueEntry[]; onOpened: (id: Ulid) => void }) {
    const scanRef = useRef<HTMLInputElement>(null);
    const open = useOpenShipment();
    const [message, setMessage] = useState<string | null>(null);
    useScanFocus(scanRef, true);

    const start = (orderNumber: string, locationCode?: string) => {
        setMessage(null);
        open.mutate(
            { order_number: orderNumber, location_code: locationCode },
            { onSuccess: (data) => onOpened(data.shipment.id), onError: (e) => setMessage(describeError(e)) },
        );
    };

    return (
        <>
            <ScanBar id="pick-scan" inputRef={scanRef} label="Scan or type an order number" busy={open.isPending} onScan={(code) => start(code)} />
            {message && <Notice tone="error">{message}</Notice>}
            <section aria-labelledby="queue-heading">
                <h2 id="queue-heading" className="mb-2 text-xl font-semibold">
                    Ready to pick
                </h2>
                {queue.length === 0 ? (
                    <p className="text-base text-slate-600">Nothing waiting.</p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {queue.map((q) => (
                            <li key={`${q.order_number}:${q.location_code}`}>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-auto min-h-12 w-full justify-between bg-white px-4 py-3 text-left text-base"
                                    disabled={open.isPending}
                                    onClick={() => (q.open_shipment_id ? onOpened(q.open_shipment_id) : start(q.order_number, q.location_code))}
                                >
                                    <span>
                                        <span className={cn('block font-semibold', MONO)}>{q.order_number}</span>
                                        <span className="block text-sm text-slate-600">
                                            {q.location_code} · {q.lines} {q.lines === 1 ? 'line' : 'lines'} · {q.fulfilment_type}
                                            {q.placed_at && <> · placed {formatTime(q.placed_at)}</>}
                                        </span>
                                    </span>
                                    <span aria-hidden>{q.open_shipment_id ? 'Resume' : 'Start'}</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

// --- A shipment's pick list ----------------------------------------------

type OpenForm = { kind: 'short' | 'substitute'; key: string } | null;

function ShipmentPanel({ shipmentId, initial, reasons, onLeave }: { shipmentId: Ulid; initial: PickListData | null; reasons: { value: string; label: string }[]; onLeave: () => void }) {
    const query = usePickList(shipmentId, initial);
    const data = query.data;
    const scanRef = useRef<HTMLInputElement>(null);
    const scan = useScanSerial(shipmentId);
    const [message, setMessage] = useState<{ tone: NoticeTone; text: string } | null>(null);
    const [openForm, setOpenForm] = useState<OpenForm>(null);
    const lineRefs = useRef(new Map<string, HTMLElement>());

    const workable = data !== undefined && ['pending', 'picking', 'picked', 'packed'].includes(data.shipment.status);
    useScanFocus(scanRef, openForm === null && workable);

    const refocusScan = () => window.setTimeout(() => scanRef.current?.focus(), 0);

    const focusLine = (line: PickLineData) => {
        const el = lineRefs.current.get(lineKey(line));
        el?.scrollIntoView({ block: 'center' });
        el?.querySelector<HTMLButtonElement>('[data-primary-action]')?.focus();
    };

    const onScan = (code: string) => {
        if (data === undefined) {
            return;
        }
        setMessage(null);

        const bySku = lineForCode(data.lines, code);
        if (bySku !== null && lineForSerial(data.lines, code) === null) {
            focusLine(bySku);
            setMessage({ tone: 'info', text: `${bySku.sku_code}: ${packsText(bySku.base_qty, bySku.pack_label, bySku.pack_base_units)}${bySku.bin_code ? ` from ${bySku.bin_code}` : ''}.` });
            return;
        }

        // Anything else is treated as a serial. The server blocks one not
        // reserved for this order — blocked, not warned (05.5 §5.2).
        scan.mutate(
            { serial_number: code },
            {
                onSuccess: (next) => {
                    const line = lineForSerial(next.lines, code);
                    const replayed = next.result?.replayed === true;
                    setMessage({ tone: 'ok', text: `${replayed ? 'Already picked' : 'Picked'}: serial ${code}${line ? ` — ${line.sku_code}` : ''}.` });
                },
                onError: (e) => setMessage({ tone: 'error', text: describeError(e) }),
            },
        );
    };

    if (query.isPending) {
        return <Notice tone="info">Loading pick list…</Notice>;
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

    const picked = data.lines.filter((l) => l.status === 'picked').length;

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
                <div className="text-right">
                    <p className="text-xl font-semibold" aria-live="polite">
                        {picked} of {data.lines.length} lines picked
                    </p>
                    <Button type="button" variant="ghost" className={cn(TARGET, 'mt-1 px-4')} onClick={onLeave}>
                        Back to the queue
                    </Button>
                </div>
            </section>

            {workable && openForm === null && <ScanBar id="pick-scan" inputRef={scanRef} label="Scan a serial, SKU or case barcode" busy={scan.isPending} onScan={onScan} />}
            {message && <Notice tone={message.tone}>{message.text}</Notice>}

            {data.shipment.status === 'dispatched' ? (
                <Notice tone="ok">This shipment was dispatched {data.shipment.dispatched_at ? formatTime(data.shipment.dispatched_at) : ''}.</Notice>
            ) : data.complete ? (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border-2 border-emerald-600 bg-emerald-50 p-4">
                    <p className="flex items-center gap-2 text-lg font-semibold text-emerald-900">
                        <Check className="size-6" aria-hidden /> Everything is picked.
                    </p>
                    <Button asChild className={cn(TARGET, 'px-6')}>
                        <Link href={`/warehouse/dispatch?shipment=${data.shipment.id}`}>
                            <Truck aria-hidden /> Go to dispatch
                        </Link>
                    </Button>
                </div>
            ) : null}

            <ol className="space-y-3" aria-label="Pick lines in walk order">
                {data.lines.map((line) => (
                    <li
                        key={lineKey(line)}
                        ref={(el) => {
                            if (el) lineRefs.current.set(lineKey(line), el);
                            else lineRefs.current.delete(lineKey(line));
                        }}
                    >
                        <PickLineCard
                            shipmentId={shipmentId}
                            line={line}
                            workable={workable}
                            reasons={reasons}
                            openForm={openForm?.key === lineKey(line) ? openForm.kind : null}
                            onOpenForm={(kind) => setOpenForm(kind === null ? null : { kind, key: lineKey(line) })}
                            onDone={(text, tone = 'ok') => {
                                setOpenForm(null);
                                setMessage({ tone, text });
                                refocusScan();
                            }}
                        />
                    </li>
                ))}
            </ol>
            {data.lines.length === 0 && <Notice tone="info">Nothing left to pick on this shipment.</Notice>}
        </>
    );
}

function PickLineCard({
    shipmentId,
    line,
    workable,
    reasons,
    openForm,
    onOpenForm,
    onDone,
}: {
    shipmentId: Ulid;
    line: PickLineData;
    workable: boolean;
    reasons: { value: string; label: string }[];
    openForm: 'short' | 'substitute' | null;
    onOpenForm: (kind: 'short' | 'substitute' | null) => void;
    onDone: (text: string, tone?: NoticeTone) => void;
}) {
    const confirm = useConfirmPick(shipmentId);
    const [error, setError] = useState<string | null>(null);
    const serialTracked = line.serials.length > 0 || line.tracking_mode === 'serial' || line.tracking_mode === 'batch_and_serial';
    const scanned = line.serials.filter((s) => s.picked).length;
    const done = line.status === 'picked';
    const ref = { line_no: line.line_no, batch_code: line.batch_code };

    return (
        <article className={cn('rounded-xl border-2 bg-white p-4', done ? 'border-emerald-600' : 'border-slate-300')} aria-label={`Line ${line.line_no}, ${line.sku_code}`}>
            <div className="flex flex-wrap items-start gap-4">
                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                    {line.thumbnail_url ? <img src={line.thumbnail_url} alt="" className="size-full object-cover" /> : <Package className="size-8 text-slate-400" aria-hidden />}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-sm uppercase tracking-wide text-slate-600">Bin</p>
                    <p className={cn('text-2xl font-bold', MONO)}>{line.bin_code ?? '—'}</p>
                    <p className="mt-1 text-lg">
                        <span className={cn('font-semibold', MONO)}>{line.sku_code}</span> {line.name}
                    </p>
                    <p className="mt-1 text-xl font-semibold">{packsText(line.base_qty, line.pack_label, line.pack_base_units)}</p>
                    {line.batch_code && (
                        <p className="mt-1 text-base">
                            Batch <span className={cn('font-semibold', MONO)}>{line.batch_code}</span>
                            {line.expires_on && <> · expires {line.expires_on}</>}
                        </p>
                    )}
                </div>
                <div className="text-right">
                    {done ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-base font-semibold text-emerald-900">
                            <Check className="size-5" aria-hidden /> Picked
                        </span>
                    ) : (
                        <span className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-base">To pick</span>
                    )}
                </div>
            </div>

            {serialTracked && (
                <div className="mt-3">
                    <p className="text-base font-semibold">
                        Serials — scan each ({scanned} of {line.serials.length})
                    </p>
                    <ul className="mt-1 flex flex-wrap gap-2">
                        {line.serials.map((s) => (
                            <li key={s.serial_number} className={cn('rounded-md border px-2 py-1 text-base', MONO, s.picked ? 'border-emerald-600 bg-emerald-50 text-emerald-900' : 'border-slate-300')}>
                                {s.picked && <Check className="mr-1 inline size-4" aria-label="scanned" />}
                                {s.serial_number}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {error && (
                <div className="mt-3">
                    <Notice tone="error">{error}</Notice>
                </div>
            )}

            {workable && !done && openForm === null && (
                <div className="mt-4 flex flex-wrap gap-3">
                    {!serialTracked && (
                        <Button
                            type="button"
                            data-primary-action
                            className={cn(TARGET, 'px-6')}
                            disabled={confirm.isPending}
                            onClick={() => {
                                setError(null);
                                confirm.mutate(ref, {
                                    onSuccess: () => onDone(`Picked ${line.sku_code}: ${packsText(line.base_qty, line.pack_label, line.pack_base_units)}.`),
                                    onError: (e) => setError(describeError(e)),
                                });
                            }}
                        >
                            <Check aria-hidden /> Picked all
                        </Button>
                    )}
                    <Button type="button" variant="outline" data-primary-action={serialTracked ? true : undefined} className={cn(TARGET, 'px-5')} onClick={() => onOpenForm('short')}>
                        <TriangleAlert aria-hidden /> Short
                    </Button>
                    {line.substitutes.length > 0 && (
                        <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} onClick={() => onOpenForm('substitute')}>
                            <Replace aria-hidden /> Other batch
                        </Button>
                    )}
                </div>
            )}

            {openForm === 'short' && <ShortPickForm shipmentId={shipmentId} line={line} reasons={reasons} scanned={serialTracked ? scanned : null} onCancel={() => onOpenForm(null)} onDone={onDone} />}
            {openForm === 'substitute' && <SubstituteForm shipmentId={shipmentId} line={line} onCancel={() => onOpenForm(null)} onDone={onDone} />}
        </article>
    );
}

function ShortPickForm({
    shipmentId,
    line,
    reasons,
    scanned,
    onCancel,
    onDone,
}: {
    shipmentId: Ulid;
    line: PickLineData;
    reasons: { value: string; label: string }[];
    scanned: number | null;
    onCancel: () => void;
    onDone: (text: string, tone?: NoticeTone) => void;
}) {
    const shortPick = useShortPick(shipmentId);
    const firstRef = useRef<HTMLInputElement>(null);
    const eachPack = line.pack_base_units <= 1;
    const [packs, setPacks] = useState(scanned === null ? '0' : String(eachPack ? scanned : Math.floor(scanned / line.pack_base_units)));
    const [loose, setLoose] = useState(scanned === null || eachPack ? '0' : String(scanned % line.pack_base_units));
    const [reason, setReason] = useState(reasons[0]?.value ?? '');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        firstRef.current?.focus();
        firstRef.current?.select();
    }, []);

    const packsN = /^\d{1,7}$/.test(packs) ? Number(packs) : null;
    const looseN = /^\d{1,7}$/.test(loose) ? Number(loose) : null;
    const pickedBase = packsN === null || looseN === null ? null : packsN * line.pack_base_units + looseN;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (pickedBase === null || pickedBase >= line.base_qty) {
            setError(`Enter fewer than ${packsText(line.base_qty, line.pack_label, line.pack_base_units)}.`);
            return;
        }
        setError(null);
        shortPick.mutate(
            { line_no: line.line_no, batch_code: line.batch_code, picked_pack_qty: packsN ?? 0, picked_loose_units: looseN ?? 0, reason },
            {
                onSuccess: (next) => {
                    const r = next.result ?? {};
                    const replanned = Number(r.replanned_base_qty ?? 0);
                    const backordered = Number(r.backordered_base_qty ?? 0);
                    onDone(
                        `Short pick recorded on ${line.sku_code}: ${Number(r.shortfall_base_qty ?? 0)} units written off.` +
                            (replanned > 0 ? ` ${replanned} re-planned from other stock.` : '') +
                            (backordered > 0 ? ` ${backordered} backordered.` : ''),
                        backordered > 0 ? 'info' : 'ok',
                    );
                },
                onError: (err) => setError(describeError(err)),
            },
        );
    };

    return (
        <form onSubmit={submit} onKeyDown={(e) => e.key === 'Escape' && onCancel()} className="mt-4 space-y-3 rounded-lg border-2 border-amber-500 bg-amber-50 p-4" aria-label={`Short pick, line ${line.line_no}`}>
            <p className="text-base font-semibold">How many did you pick? The rest is written off and re-planned.</p>
            <div className="flex flex-wrap items-end gap-3">
                {!eachPack && (
                    <div>
                        <label htmlFor={`short-packs-${line.line_no}`} className="block text-base">
                            {line.pack_label}
                        </label>
                        <Input id={`short-packs-${line.line_no}`} ref={firstRef} value={packs} onChange={(e) => setPacks(e.target.value.replace(/\D/g, ''))} inputMode="numeric" className={cn(FIELD, 'mt-1 w-28 tabular-nums')} readOnly={scanned !== null} />
                    </div>
                )}
                <div>
                    <label htmlFor={`short-loose-${line.line_no}`} className="block text-base">
                        {eachPack ? 'Units' : 'Loose units'}
                    </label>
                    <Input
                        id={`short-loose-${line.line_no}`}
                        ref={eachPack ? firstRef : undefined}
                        value={eachPack ? packs : loose}
                        onChange={(e) => (eachPack ? setPacks(e.target.value.replace(/\D/g, '')) : setLoose(e.target.value.replace(/\D/g, '')))}
                        inputMode="numeric"
                        className={cn(FIELD, 'mt-1 w-28 tabular-nums')}
                        readOnly={scanned !== null}
                    />
                </div>
                <div>
                    <label htmlFor={`short-reason-${line.line_no}`} className="block text-base">
                        Reason
                    </label>
                    <select id={`short-reason-${line.line_no}`} value={reason} onChange={(e) => setReason(e.target.value)} className={cn(FIELD, 'mt-1 rounded-md border border-slate-400 bg-white px-3')}>
                        {reasons.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            {scanned !== null && <p className="text-sm text-slate-700">Serial-tracked: the picked quantity is the {scanned} serials scanned. Unscanned serials are quarantined as missing.</p>}
            <p className="text-base" aria-live="polite">
                Picked {pickedBase ?? '—'} of {line.base_qty} units
            </p>
            {error && <Notice tone="error">{error}</Notice>}
            <div className="flex flex-wrap gap-3">
                <Button type="submit" className={cn(TARGET, 'px-6')} disabled={shortPick.isPending}>
                    Record short pick
                </Button>
                <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} onClick={onCancel}>
                    Cancel (Esc)
                </Button>
            </div>
        </form>
    );
}

function SubstituteForm({ shipmentId, line, onCancel, onDone }: { shipmentId: Ulid; line: PickLineData; onCancel: () => void; onDone: (text: string, tone?: NoticeTone) => void }) {
    const substitute = useSubstituteBatch(shipmentId);
    const firstRef = useRef<HTMLSelectElement>(null);
    const [batch, setBatch] = useState(line.substitutes[0]?.batch_code ?? '');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => firstRef.current?.focus(), []);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (line.batch_code === null || batch === '' || reason.trim().length < 3) {
            setError('Choose the batch you are taking and say why.');
            return;
        }
        setError(null);
        substitute.mutate(
            { line_no: line.line_no, batch_code: line.batch_code, new_batch_code: batch, reason: reason.trim() },
            {
                onSuccess: () => onDone(`Line ${line.line_no} now takes batch ${batch} instead of ${line.batch_code}. Recorded against you with your reason.`),
                onError: (err) => setError(describeError(err)),
            },
        );
    };

    return (
        <form onSubmit={submit} onKeyDown={(e) => e.key === 'Escape' && onCancel()} className="mt-4 space-y-3 rounded-lg border-2 border-slate-800 bg-slate-50 p-4" aria-label={`Substitute batch, line ${line.line_no}`}>
            <p className="text-base font-semibold">
                Take a different batch than <span className={MONO}>{line.batch_code}</span>. This is recorded — the delivery note and recall trace follow it.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor={`sub-batch-${line.line_no}`} className="block text-base">
                        Batch taken
                    </label>
                    <select id={`sub-batch-${line.line_no}`} ref={firstRef} value={batch} onChange={(e) => setBatch(e.target.value)} className={cn(FIELD, 'mt-1 w-full rounded-md border border-slate-400 bg-white px-3', MONO)}>
                        {line.substitutes.map((s) => (
                            <option key={s.batch_code} value={s.batch_code}>
                                {s.batch_code}
                                {s.expires_on ? ` — exp ${s.expires_on}` : ''} ({s.available_base_qty} available)
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor={`sub-reason-${line.line_no}`} className="block text-base">
                        Why
                    </label>
                    <Input id={`sub-reason-${line.line_no}`} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. behind a full pallet" className={cn(FIELD, 'mt-1')} />
                </div>
            </div>
            {error && <Notice tone="error">{error}</Notice>}
            <div className="flex flex-wrap gap-3">
                <Button type="submit" className={cn(TARGET, 'px-6')} disabled={substitute.isPending}>
                    Substitute batch
                </Button>
                <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} onClick={onCancel}>
                    Cancel (Esc)
                </Button>
            </div>
        </form>
    );
}
