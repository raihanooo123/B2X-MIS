/**
 * Stocktake (05.5 §8, §9; 02 §24). A session per location; trading goes
 * on throughout, and nothing reaches the ledger until the count is posted.
 *
 *   1. Start (or resume) the session for a location, optionally **blind**:
 *      no system figure is shown while counting, so the operative counts
 *      rather than confirms.
 *   2. Count. Scan a case or SKU barcode; enter packs plus loose units for
 *      a batch or untracked SKU, or scan each unit of a serial-tracked one
 *      (a mis-scan can be removed). A recount replaces the line and is
 *      measured from that moment.
 *   3. Review. Each line shows the level as it stood when it was counted —
 *      reconstructed from the movements since, so sales during the count
 *      are not variances — the variance, and missing and found serials by
 *      number. Every variance needs a reason.
 *   4. Post: one transaction; the server re-checks everything.
 *
 * Scan-everywhere and keyboard-complete like the other warehouse screens:
 * a key pressed with nothing focused lands in the scan bar, Enter moves
 * between fields, Esc closes a form. 48 px targets; codes in monospace.
 */
import { Head } from '@inertiajs/react';
import { Check, ClipboardCheck, Eye, EyeOff, X } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FIELD, MONO, Notice, ScanBar, TARGET, describeError, formatTime, useScanFocus, type NoticeTone } from '@/components/warehouse/scan';
import { ApiError, type ApiErrorDetail } from '@/lib/api/client';
import { lookupCode, type ReceivingSku } from '@/lib/api/goodsIn';
import {
    useCancelStocktake,
    useCountLine,
    usePostStocktake,
    useRemoveStocktakeSerial,
    useReopenStocktake,
    useScanStocktakeSerial,
    useStartStocktake,
    useStocktake,
    useSubmitForReview,
    type StocktakeData,
    type StocktakeLineData,
    type Ulid,
} from '@/lib/api/stocktake';
import { cn } from '@/lib/utils';

interface InProgress {
    id: Ulid;
    location_code: string | null;
    status: string;
    is_blind: boolean;
    started_at: string;
    line_count: number;
}

interface StocktakeProps {
    stocktake: StocktakeData | null;
    in_progress: InProgress[];
    locations: { code: string; name: string }[];
    reasons: { value: string; label: string }[];
    can_count: boolean;
}

function stocktakeUrl(id: Ulid | null): string {
    return id === null ? '/warehouse/stocktake' : `/warehouse/stocktake?stocktake=${id}`;
}

const isSerial = (mode: string | null) => mode === 'serial' || mode === 'batch_and_serial';
const isBatch = (mode: string | null) => mode === 'batch' || mode === 'batch_and_serial';

export default function Stocktake(props: StocktakeProps) {
    const [id, setId] = useState<Ulid | null>(props.stocktake?.id ?? null);

    const go = (next: Ulid | null) => {
        setId(next);
        window.history.replaceState(window.history.state, '', stocktakeUrl(next));
    };

    return (
        <div className="min-h-screen bg-slate-100 pb-24 text-lg text-slate-900 antialiased">
            <Head title="Stocktake" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-3">
                        <ClipboardCheck className="size-7 text-slate-700" aria-hidden />
                        <h1 className="text-2xl font-bold">Stocktake</h1>
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <main className="mx-auto max-w-5xl space-y-6 px-4 pt-6">
                {id === null ? <StartPanel {...props} onOpened={go} /> : <SessionPanel key={id} id={id} props={props} onLeave={() => go(null)} />}
            </main>
        </div>
    );
}

// --- 1. Start or resume --------------------------------------------------

function StartPanel({ in_progress: inProgress, locations, can_count: canCount, onOpened }: StocktakeProps & { onOpened: (id: Ulid) => void }) {
    const start = useStartStocktake();
    const [location, setLocation] = useState(locations[0]?.code ?? '');
    const [blind, setBlind] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        start.mutate({ location_code: location, blind }, { onSuccess: (s) => onOpened(s.id), onError: (err) => setError(describeError(err)) });
    };

    return (
        <>
            {canCount && (
                <form onSubmit={submit} className="space-y-4 rounded-xl border-2 border-slate-800 bg-white p-5" aria-labelledby="start-heading">
                    <h2 id="start-heading" className="text-xl font-semibold">
                        Start a count
                    </h2>
                    <div className="flex flex-wrap items-end gap-4">
                        <div>
                            <label htmlFor="st-location" className="block text-base font-medium">
                                Location
                            </label>
                            <select id="st-location" value={location} onChange={(e) => setLocation(e.target.value)} className={cn(FIELD, 'mt-1 rounded-md border border-slate-400 bg-white px-3')}>
                                {locations.map((l) => (
                                    <option key={l.code} value={l.code}>
                                        {l.code} — {l.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <label className="flex min-h-12 cursor-pointer items-center gap-3 text-base">
                            <input type="checkbox" checked={blind} onChange={(e) => setBlind(e.target.checked)} className="size-6" />
                            Blind count — hide system figures while counting
                        </label>
                        <Button type="submit" className={cn(TARGET, 'px-6')} disabled={location === '' || start.isPending}>
                            Start
                        </Button>
                    </div>
                    <p className="text-sm text-slate-600">One count per location at a time: if one is already open there, this resumes it. Trading carries on while you count.</p>
                    {error && <Notice tone="error">{error}</Notice>}
                </form>
            )}

            <section aria-labelledby="progress-heading">
                <h2 id="progress-heading" className="mb-2 text-xl font-semibold">
                    In progress
                </h2>
                {inProgress.length === 0 ? (
                    <p className="text-base text-slate-600">None.</p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {inProgress.map((s) => (
                            <li key={s.id}>
                                <Button type="button" variant="outline" className="h-auto min-h-12 w-full justify-between bg-white px-4 py-3 text-left text-base" onClick={() => onOpened(s.id)}>
                                    <span>
                                        <span className={cn('block font-semibold', MONO)}>{s.location_code}</span>
                                        <span className="block text-sm text-slate-600">
                                            {s.status === 'review' ? 'In review' : 'Counting'} · {s.line_count} lines · {s.is_blind ? 'blind' : 'open'} · started {formatTime(s.started_at)}
                                        </span>
                                    </span>
                                    <span aria-hidden>Resume</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

// --- 2–4. A session ------------------------------------------------------

interface CountTarget {
    sku: ReceivingSku;
    packCode: string | null;
    batchCode: string | null;
}

function SessionPanel({ id, props, onLeave }: { id: Ulid; props: StocktakeProps; onLeave: () => void }) {
    const query = useStocktake(id, props.stocktake);
    const data = query.data;
    const scanRef = useRef<HTMLInputElement>(null);
    const [target, setTarget] = useState<CountTarget | null>(null);
    const [candidates, setCandidates] = useState<ReceivingSku[] | null>(null);
    const [message, setMessage] = useState<{ tone: NoticeTone; text: string } | null>(null);
    const [looking, setLooking] = useState(false);
    const counting = data?.status === 'open' && props.can_count;
    useScanFocus(scanRef, counting && target === null);

    const refocusScan = () => window.setTimeout(() => scanRef.current?.focus(), 0);

    const onScan = async (code: string) => {
        setMessage(null);
        setCandidates(null);
        setLooking(true);
        try {
            const found = await lookupCode(code, null);
            if (found.kind === 'sku') {
                setTarget({ sku: found.sku, packCode: found.pack_code, batchCode: null });
            } else if (found.kind === 'unknown' && found.candidates.length > 0) {
                setCandidates(found.candidates);
                setMessage({ tone: 'info', text: `${found.code} was not recognised. Did you mean one of these?` });
            } else {
                setMessage({ tone: 'error', text: `${code} is not a SKU or case barcode. Scan the item, not the ${found.kind === 'bin' ? 'bin' : 'document'}.` });
            }
        } catch (e) {
            setMessage({ tone: 'error', text: describeError(e) });
        } finally {
            setLooking(false);
        }
    };

    if (query.isPending) {
        return <Notice tone="info">Loading stocktake…</Notice>;
    }
    if (data === undefined) {
        return (
            <>
                <Notice tone="error">{describeError(query.error)}</Notice>
                <Button type="button" variant="outline" className={TARGET} onClick={onLeave}>
                    Back
                </Button>
            </>
        );
    }

    return (
        <>
            <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white p-4">
                <div>
                    <p className="text-sm uppercase tracking-wide text-slate-600">Stocktake · {data.location.name}</p>
                    <p className={cn('text-2xl font-bold', MONO)}>{data.location.code}</p>
                    <p className="flex items-center gap-2 text-base text-slate-600">
                        {data.is_blind ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
                        {data.is_blind ? 'Blind count' : 'Open count'} · {data.lines.length} lines · started {formatTime(data.started_at)}
                    </p>
                </div>
                <div className="flex flex-wrap gap-3">
                    <StatusActions data={data} canCount={props.can_count} onMessage={setMessage} />
                    <Button type="button" variant="ghost" className={cn(TARGET, 'px-4')} onClick={onLeave}>
                        Back
                    </Button>
                </div>
            </section>

            {counting && target === null && <ScanBar id="stocktake-scan" inputRef={scanRef} label="Scan a case or SKU barcode, or type a SKU code" busy={looking} onScan={(c) => void onScan(c)} />}
            {message && <Notice tone={message.tone}>{message.text}</Notice>}

            {candidates && target === null && (
                <ul className="space-y-2" aria-label="Did you mean">
                    {candidates.map((c) => (
                        <li key={c.id}>
                            <Button type="button" variant="outline" className="h-auto min-h-12 w-full justify-start px-4 py-3 text-left text-base" onClick={() => setTarget({ sku: c, packCode: null, batchCode: null })}>
                                <span className={MONO}>{c.sku_code}</span> <span className="text-slate-600">{c.name}</span>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {target !== null && (
                <CountForm
                    key={`${target.sku.id}:${target.batchCode ?? ''}`}
                    stocktakeId={data.id}
                    target={target}
                    line={data.lines.find((l) => l.sku_id === target.sku.id && (l.batch_code ?? null) === target.batchCode) ?? null}
                    lines={data.lines}
                    onDone={(text) => {
                        setTarget(null);
                        setMessage({ tone: 'ok', text });
                        refocusScan();
                    }}
                    onCancel={() => {
                        setTarget(null);
                        refocusScan();
                    }}
                />
            )}

            {data.status === 'review' ? (
                <ReviewPanel data={data} reasons={props.reasons} canCount={props.can_count} />
            ) : (
                <CountedList data={data} counting={counting && target === null} onRecount={(line) => line.sku_id && setTarget({ sku: skuStub(line), packCode: null, batchCode: line.batch_code })} />
            )}
        </>
    );
}

/** A recount starts from the counted line; the form fetches packs by scanning the SKU code. */
function skuStub(line: StocktakeLineData): ReceivingSku {
    return {
        id: line.sku_id ?? '',
        sku_code: line.sku_code ?? '',
        name: line.name,
        barcode: null,
        tracking_mode: line.tracking_mode ?? 'none',
        requires_expiry: false,
        expiry_prefill: null,
        packs: [],
    };
}

function StatusActions({ data, canCount, onMessage }: { data: StocktakeData; canCount: boolean; onMessage: (m: { tone: NoticeTone; text: string }) => void }) {
    const review = useSubmitForReview(data.id);
    const reopen = useReopenStocktake(data.id);
    const cancel = useCancelStocktake(data.id);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const onError = (e: ApiError) => onMessage({ tone: 'error', text: describeError(e) });

    if (!canCount || (data.status !== 'open' && data.status !== 'review')) {
        return null;
    }

    return (
        <>
            {data.status === 'open' ? (
                <Button type="button" className={cn(TARGET, 'px-5')} disabled={data.lines.length === 0 || review.isPending} onClick={() => review.mutate(undefined, { onError })}>
                    Finish counting
                </Button>
            ) : (
                <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} disabled={reopen.isPending} onClick={() => reopen.mutate(undefined, { onError })}>
                    Count more
                </Button>
            )}
            {confirmCancel ? (
                <Button type="button" variant="destructive" className={cn(TARGET, 'px-5')} disabled={cancel.isPending} onClick={() => cancel.mutate(undefined, { onError })}>
                    Confirm: discard this count
                </Button>
            ) : (
                <Button type="button" variant="ghost" className={cn(TARGET, 'px-4')} onClick={() => setConfirmCancel(true)}>
                    Cancel stocktake
                </Button>
            )}
        </>
    );
}

// --- Counting ------------------------------------------------------------

function CountForm({
    stocktakeId,
    target,
    line,
    lines,
    onDone,
    onCancel,
}: {
    stocktakeId: Ulid;
    target: CountTarget;
    line: StocktakeLineData | null;
    lines: StocktakeLineData[];
    onDone: (text: string) => void;
    onCancel: () => void;
}) {
    const { sku } = target;
    const count = useCountLine(stocktakeId);
    const scanSerial = useScanStocktakeSerial(stocktakeId);
    const removeSerial = useRemoveStocktakeSerial(stocktakeId);
    const firstRef = useRef<HTMLInputElement>(null);
    const [packs, setPacks] = useState(sku.packs);
    const [batchCode, setBatchCode] = useState(target.batchCode ?? '');
    const [packCode, setPackCode] = useState(target.packCode ?? sku.packs[sku.packs.length - 1]?.code ?? '');
    const [packQty, setPackQty] = useState('');
    const [loose, setLoose] = useState('0');
    const [serialInput, setSerialInput] = useState('');
    const [errors, setErrors] = useState<ApiErrorDetail[]>([]);
    const [error, setError] = useState<string | null>(null);

    const serial = isSerial(sku.tracking_mode);
    const batch = isBatch(sku.tracking_mode);
    const identity = { sku_id: sku.id, batch_code: batch ? batchCode.trim() || null : null };
    const current = lines.find((l) => l.sku_id === sku.id && (l.batch_code ?? null) === identity.batch_code) ?? line;

    useEffect(() => {
        firstRef.current?.focus();
        // A recount from the list has no packs yet: resolve them from the SKU code.
        if (sku.packs.length === 0 && sku.sku_code !== '') {
            void lookupCode(sku.sku_code, null)
                .then((r) => {
                    if (r.kind === 'sku') {
                        setPacks(r.sku.packs);
                        setPackCode((c) => c || (r.sku.packs[r.sku.packs.length - 1]?.code ?? ''));
                    }
                })
                .catch(() => undefined);
        }
    }, [sku.packs.length, sku.sku_code]);

    const pack = packs.find((p) => p.code === packCode) ?? null;
    const qty = /^\d{1,7}$/.test(packQty) ? Number(packQty) : null;
    const looseN = /^\d{1,7}$/.test(loose) ? Number(loose) : null;
    const base = pack && qty !== null && looseN !== null ? qty * pack.base_units + looseN : null;

    const fail = (e: unknown) => {
        if (e instanceof ApiError) {
            setErrors(e.details);
        }
        setError(describeError(e));
    };

    const submitCount = (e: FormEvent) => {
        e.preventDefault();
        if (batch && identity.batch_code === null) {
            setError('Scan or enter the batch code: each batch is counted separately.');
            return;
        }
        if (pack === null || base === null) {
            setError('Enter how many packs, and any loose units.');
            return;
        }
        setError(null);
        count.mutate(
            { ...identity, pack_code: pack.code, pack_qty: qty ?? 0, loose_units: looseN ?? 0 },
            { onSuccess: () => onDone(`Counted ${sku.sku_code}${identity.batch_code ? ` batch ${identity.batch_code}` : ''}: ${base} units.`), onError: fail },
        );
    };

    const submitSerial = (e: FormEvent) => {
        e.preventDefault();
        const value = serialInput.trim();
        if (value === '') {
            return;
        }
        if (batch && identity.batch_code === null) {
            setError('Enter the batch code first.');
            return;
        }
        setError(null);
        scanSerial.mutate({ ...identity, serial_number: value }, { onSuccess: () => setSerialInput(''), onError: fail });
    };

    const fieldError = (field: string) => errors.find((d) => d.field === field)?.message ?? null;

    return (
        <section className="space-y-4 rounded-xl border-2 border-slate-800 bg-white p-5" aria-labelledby="count-heading" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
            <h2 id="count-heading" className="text-2xl font-bold">
                <span className={MONO}>{sku.sku_code}</span> <span className="font-normal text-slate-700">{sku.name}</span>
            </h2>
            {current && (
                <p className="text-base text-slate-700">
                    Counted so far: <strong>{current.counted_base_qty}</strong> at {formatTime(current.counted_at)} — a recount replaces it.
                    {current.system_base_qty !== undefined && <> System shows {current.system_base_qty}.</>}
                </p>
            )}

            {batch && (
                <div>
                    <label htmlFor="count-batch" className="block text-base font-semibold">
                        Batch code <span className="font-normal text-red-700">required</span>
                    </label>
                    <Input id="count-batch" ref={firstRef} value={batchCode} onChange={(e) => setBatchCode(e.target.value)} autoCapitalize="characters" spellCheck={false} autoComplete="off" className={cn(FIELD, 'mt-1 w-72', MONO)} aria-invalid={fieldError('batch_code') !== null} />
                    {fieldError('batch_code') && <p className="mt-1 text-base text-red-700">{fieldError('batch_code')}</p>}
                </div>
            )}

            {serial ? (
                <div className="space-y-3">
                    <form onSubmit={submitSerial} className="flex flex-wrap items-end gap-3">
                        <div>
                            <label htmlFor="count-serial" className="block text-base font-semibold">
                                Scan each unit's serial
                            </label>
                            <Input id="count-serial" ref={batch ? undefined : firstRef} value={serialInput} onChange={(e) => setSerialInput(e.target.value)} autoCapitalize="characters" spellCheck={false} autoComplete="off" className={cn(FIELD, 'mt-1 w-80', MONO)} />
                        </div>
                        <Button type="submit" className={cn(TARGET, 'px-5')} disabled={scanSerial.isPending}>
                            Add
                        </Button>
                    </form>
                    <p className="text-base font-semibold" aria-live="polite">
                        {current?.serials.length ?? 0} scanned
                    </p>
                    <ul className="flex flex-wrap gap-2">
                        {(current?.serials ?? []).map((s) => (
                            <li key={s} className={cn('flex items-center gap-1 rounded-md border border-slate-300 pl-2 text-base', MONO)}>
                                {s}
                                <Button type="button" variant="ghost" className="size-12 p-0" aria-label={`Remove ${s}`} onClick={() => removeSerial.mutate({ ...identity, serial_number: s }, { onError: fail })}>
                                    <X aria-hidden />
                                </Button>
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" className={cn(TARGET, 'px-6')} onClick={() => onDone(`${sku.sku_code}: ${current?.serials.length ?? 0} serials scanned.`)}>
                            <Check aria-hidden /> Done
                        </Button>
                        {(current?.serials.length ?? 0) === 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                className={cn(TARGET, 'px-5')}
                                onClick={() => count.mutate({ ...identity, pack_code: packs[0]?.code ?? '', pack_qty: 0, loose_units: 0 }, { onSuccess: () => onDone(`Counted ${sku.sku_code}: none on the shelf.`), onError: fail })}
                            >
                                None on the shelf
                            </Button>
                        )}
                        <Button type="button" variant="ghost" className={cn(TARGET, 'px-4')} onClick={onCancel}>
                            Close (Esc)
                        </Button>
                    </div>
                </div>
            ) : (
                <form onSubmit={submitCount} className="space-y-3">
                    <fieldset>
                        <legend className="text-base font-semibold">Pack</legend>
                        <div className="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Pack">
                            {packs.map((p) => (
                                <label key={p.code} className={cn('flex min-h-12 cursor-pointer items-center gap-2 rounded-lg border-2 px-4 text-base', p.code === packCode ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white')}>
                                    <input type="radio" name="count-pack" value={p.code} checked={p.code === packCode} onChange={() => setPackCode(p.code)} className="size-5" />
                                    {p.label} <span className="text-sm opacity-80">({p.base_units})</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div className="flex flex-wrap items-end gap-3">
                        <div>
                            <label htmlFor="count-qty" className="block text-base font-semibold">
                                {pack?.label ?? 'Packs'}
                            </label>
                            <Input id="count-qty" ref={batch ? undefined : firstRef} value={packQty} onChange={(e) => setPackQty(e.target.value.replace(/\D/g, ''))} inputMode="numeric" className={cn(FIELD, 'mt-1 w-32 text-2xl tabular-nums')} />
                        </div>
                        {pack !== null && pack.base_units > 1 && (
                            <div>
                                <label htmlFor="count-loose" className="block text-base font-semibold">
                                    Loose units
                                </label>
                                <Input id="count-loose" value={loose} onChange={(e) => setLoose(e.target.value.replace(/\D/g, ''))} inputMode="numeric" className={cn(FIELD, 'mt-1 w-32 tabular-nums')} />
                            </div>
                        )}
                        <p className="pb-2 text-xl font-semibold" aria-live="polite">
                            = {base ?? '—'} units
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Button type="submit" className={cn(TARGET, 'px-6')} disabled={count.isPending}>
                            <Check aria-hidden /> Save count
                        </Button>
                        <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} onClick={onCancel}>
                            Cancel (Esc)
                        </Button>
                    </div>
                </form>
            )}
            {error && <Notice tone="error">{error}</Notice>}
        </section>
    );
}

function CountedList({ data, counting, onRecount }: { data: StocktakeData; counting: boolean; onRecount: (line: StocktakeLineData) => void }) {
    return (
        <section aria-labelledby="counted-heading" className="rounded-xl border border-slate-300 bg-white p-4">
            <h2 id="counted-heading" className="text-xl font-semibold">
                {data.status === 'posted' ? `Posted ${data.posted_at ? formatTime(data.posted_at) : ''}` : 'Counted'}
            </h2>
            {data.lines.length === 0 ? (
                <p className="mt-2 text-base text-slate-600">Nothing counted yet.</p>
            ) : (
                <ul className="mt-2 divide-y divide-slate-200">
                    {data.lines.map((l) => (
                        <li key={l.line_id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-base">
                            <span className={cn('font-semibold', MONO)}>{l.sku_code}</span>
                            {l.batch_code && (
                                <span>
                                    batch <span className={MONO}>{l.batch_code}</span>
                                </span>
                            )}
                            <span>
                                counted <strong>{l.counted_base_qty}</strong>
                            </span>
                            {l.system_base_qty !== undefined && <span className="text-slate-600">system {l.system_base_qty}</span>}
                            {l.posted && l.posted.variance_base_qty !== null && (
                                <span className={cn('font-semibold', l.posted.variance_base_qty === 0 ? 'text-slate-600' : 'text-amber-800')}>
                                    variance {l.posted.variance_base_qty > 0 ? '+' : ''}
                                    {l.posted.variance_base_qty}
                                </span>
                            )}
                            {l.serials.length > 0 && <span className="text-slate-600">{l.serials.length} serials</span>}
                            <span className="ml-auto text-sm text-slate-600">{formatTime(l.counted_at)}</span>
                            {counting && (
                                <Button type="button" variant="outline" className={cn(TARGET, 'px-4')} onClick={() => onRecount(l)}>
                                    Recount
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

// --- Review and post -----------------------------------------------------

function ReviewPanel({ data, reasons, canCount }: { data: StocktakeData; reasons: { value: string; label: string }[]; canCount: boolean }) {
    const post = usePostStocktake(data.id);
    const [chosen, setChosen] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<ApiErrorDetail[]>([]);
    const [error, setError] = useState<string | null>(null);

    const discrepancies = data.lines.filter((l) => (l.review?.variance_base_qty ?? 0) !== 0 || (l.review?.missing_serials.length ?? 0) > 0 || (l.review?.found_serials.length ?? 0) > 0);
    const blocked = data.lines.some((l) => (l.review?.blockers.length ?? 0) > 0);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const missing = discrepancies.filter((l) => !chosen[l.line_id]);
        if (missing.length > 0) {
            setError(`Choose a reason for ${missing.map((l) => l.sku_code).join(', ')}.`);
            return;
        }
        setError(null);
        post.mutate(
            { reasons: discrepancies.map((l) => ({ sku_id: l.sku_id ?? '', batch_code: l.batch_code, reason: chosen[l.line_id] })) },
            {
                onError: (err) => {
                    setErrors(err instanceof ApiError ? err.details : []);
                    setError(describeError(err));
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-xl border-2 border-slate-800 bg-white p-5" aria-labelledby="review-heading">
            <h2 id="review-heading" className="text-2xl font-bold">
                Review
            </h2>
            <p className="text-base text-slate-700">Each line is measured against stock as it stood when it was counted, so anything sold or received since is not a variance.</p>
            <ul className="space-y-3">
                {data.lines.map((l) => {
                    const r = l.review;
                    const variance = r?.variance_base_qty ?? 0;
                    const lineErrors = errors.filter((d) => d.field === `lines.${l.line_id}`);

                    return (
                        <li key={l.line_id} className={cn('rounded-lg border-2 p-3', variance === 0 ? 'border-slate-200' : 'border-amber-500 bg-amber-50')}>
                            <p className="text-base">
                                <span className={cn('font-semibold', MONO)}>{l.sku_code}</span>
                                {l.batch_code && (
                                    <>
                                        {' '}
                                        batch <span className={MONO}>{l.batch_code}</span>
                                    </>
                                )}{' '}
                                — counted <strong>{l.counted_base_qty}</strong>, expected <strong>{r?.expected_at_count ?? '—'}</strong> when counted
                                {r && r.on_hand_now !== r.expected_at_count && <> (now {r.on_hand_now})</>}:{' '}
                                <strong className={variance === 0 ? 'text-slate-700' : 'text-amber-900'}>
                                    variance {variance > 0 ? '+' : ''}
                                    {variance}
                                </strong>
                            </p>
                            {r && r.missing_serials.length > 0 && (
                                <p className="mt-1 text-base">
                                    Missing: <span className={MONO}>{r.missing_serials.join(', ')}</span>
                                </p>
                            )}
                            {r && r.found_serials.length > 0 && (
                                <p className="mt-1 text-base">
                                    Found: <span className={MONO}>{r.found_serials.join(', ')}</span>
                                </p>
                            )}
                            {r?.blockers.map((b) => (
                                <p key={b} role="alert" className="mt-1 text-base font-semibold text-red-800">
                                    {b}
                                </p>
                            ))}
                            {(variance !== 0 || (r?.missing_serials.length ?? 0) > 0 || (r?.found_serials.length ?? 0) > 0) && canCount && (
                                <div className="mt-2">
                                    <label htmlFor={`reason-${l.line_id}`} className="block text-base font-medium">
                                        Reason
                                    </label>
                                    <select
                                        id={`reason-${l.line_id}`}
                                        value={chosen[l.line_id] ?? ''}
                                        onChange={(e) => setChosen((c) => ({ ...c, [l.line_id]: e.target.value }))}
                                        className={cn(FIELD, 'mt-1 w-full max-w-md rounded-md border border-slate-400 bg-white px-3')}
                                    >
                                        <option value="">Choose…</option>
                                        {reasons.map((reason) => (
                                            <option key={reason.value} value={reason.value}>
                                                {reason.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            {lineErrors.map((d) => (
                                <p key={d.message} className="mt-1 text-base text-red-700">
                                    {d.message}
                                </p>
                            ))}
                        </li>
                    );
                })}
            </ul>
            {error && <Notice tone="error">{error}</Notice>}
            {canCount && (
                <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                    <Button type="submit" className={cn(TARGET, 'px-8 text-lg')} disabled={post.isPending || blocked}>
                        {post.isPending ? 'Posting…' : 'Post stocktake'}
                    </Button>
                    {blocked && <p className="text-base text-red-800">Resolve the blocked lines first.</p>}
                </div>
            )}
        </form>
    );
}
