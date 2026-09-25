/**
 * Goods-in (05.5 §4.2, §9): identify the source, pick the line, count
 * packs, capture batch / expiry / serials where the SKU needs them, put
 * away, confirm. Built for warehouse conditions, not a desk:
 *
 *   - **Scan everywhere.** One scan bar resolves anything (PO number,
 *     container, case or SKU barcode, SKU code, bin). A printable key
 *     pressed while no field has focus lands in the scan bar, so a wedge
 *     scanner works without anyone touching the screen first. Every
 *     identifier field (batch, bin, serials) also takes a scan directly.
 *   - **Keyboard-complete.** Enter moves to the next field rather than
 *     submitting — a scanner ends every scan with Enter, and a scanned
 *     batch code must not book the entry. Ctrl+Enter (⌘+Enter) books it;
 *     Esc cancels it. In the serials box Enter is a new line, so scanning
 *     a run of serials just works.
 *   - **48 px targets** throughout, no hover-only interaction, a larger
 *     base type, and batch codes and serials in a monospaced face (0/O
 *     and 1/I confusion breaks a recall trace).
 *
 * The server is authoritative for every rule; the screen shows the same
 * rules before the request (lib/goodsIn/entry.ts). Quantities are entered
 * in packs with the base-unit equivalent shown live — never units, which
 * invites the arithmetic that books 43 instead of 432. The PO's ordered
 * quantity is shown but deliberately not pre-filled: pre-filling it is
 * the workaround 05.5 §4.4 warns loses the variance.
 */
import { Head } from '@inertiajs/react';
import { AlertTriangle, Check, PackageCheck, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent, type KeyboardEvent as ReactKeyboardEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { FIELD, MONO, Notice, ScanBar, TARGET, describeError as describe, formatTime, useScanFocus } from '@/components/warehouse/scan';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError, type ApiErrorDetail } from '@/lib/api/client';
import {
    lookupCode,
    useCloseReceipt,
    useOpenReceipt,
    useReceipt,
    useReceiveLine,
    type ExpectedLine,
    type GoodsReceipt,
    type ReceiveLineInput,
    type ReceivingSku,
    type Ulid,
} from '@/lib/api/goodsIn';
import {
    baseQtyOf,
    duplicateSerials,
    entryFingerprint,
    expiryWarnings,
    londonToday,
    packEquivalent,
    parsePackQty,
    parseSerials,
    poundsToE4,
    tracksBatch,
    tracksSerial,
    type ExpiryWarning,
} from '@/lib/goodsIn/entry';
import { cn } from '@/lib/utils';

interface OpenReceiptSummary {
    id: Ulid;
    source: string;
    reference: string | null;
    location_code: string | null;
    opened_at: string;
    line_count: number;
}

interface GoodsInProps {
    receipt: GoodsReceipt | null;
    open_receipts: OpenReceiptSummary[];
    locations: { code: string; name: string }[];
    variance_reasons: { value: string; label: string }[];
    default_horizon_days: number;
    can_receive: boolean;
}

const WARNING_TEXT: Record<ExpiryWarning, string> = {
    expiry_in_past: 'This expiry date is in the past.',
    expiry_beyond_horizon: 'This expiry date is unusually far ahead — check the year.',
};

function goodsInUrl(receiptId: Ulid | null): string {
    return receiptId === null ? '/warehouse/goods-in' : `/warehouse/goods-in?receipt=${receiptId}`;
}

export default function GoodsIn(props: GoodsInProps) {
    const [receiptId, setReceiptId] = useState<Ulid | null>(props.receipt?.id ?? null);

    const go = (id: Ulid | null) => {
        setReceiptId(id);
        window.history.replaceState(window.history.state, '', goodsInUrl(id));
    };

    return (
        <div className="min-h-screen bg-slate-100 pb-24 text-lg text-slate-900 antialiased">
            <Head title="Goods in" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                    <div className="flex items-center gap-3">
                        <PackageCheck className="size-7 text-slate-700" aria-hidden />
                        <h1 className="text-2xl font-bold">Goods in</h1>
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <main className="mx-auto max-w-6xl space-y-6 px-4 pt-6">
                {receiptId === null ? (
                    <StartPanel {...props} onOpened={go} />
                ) : (
                    <ReceiptPanel key={receiptId} receiptId={receiptId} props={props} onLeave={() => go(null)} />
                )}
            </main>
        </div>
    );
}

// --- 1. Identify the source ----------------------------------------------

function StartPanel({ open_receipts: openReceipts, locations, can_receive: canReceive, onOpened }: GoodsInProps & { onOpened: (id: Ulid) => void }) {
    const scanRef = useRef<HTMLInputElement>(null);
    const open = useOpenReceipt();
    const [message, setMessage] = useState<{ tone: 'error' | 'info'; text: string } | null>(null);
    const [locationCode, setLocationCode] = useState(locations[0]?.code ?? '');
    const [looking, setLooking] = useState(false);
    useScanFocus(scanRef, true);

    const openFrom = (input: Parameters<typeof open.mutate>[0]) => {
        setMessage(null);
        open.mutate(input, {
            onSuccess: (receipt) => onOpened(receipt.id),
            onError: (e) => setMessage({ tone: 'error', text: describe(e) }),
        });
    };

    const onScan = async (code: string) => {
        setMessage(null);
        setLooking(true);
        try {
            const found = await lookupCode(code, null);
            if (found.kind === 'purchase_order' || found.kind === 'container') {
                if (!canReceive) {
                    setMessage({ tone: 'info', text: `${found.reference} is ${found.status}. You can view receipts but not book goods in.` });
                } else {
                    openFrom({ source: found.kind, reference: found.reference });
                }
            } else {
                setMessage({ tone: 'info', text: `${code} is not a purchase order or container. Scan the PO number or container reference first, or start a manual receipt.` });
            }
        } catch (e) {
            setMessage({ tone: 'error', text: describe(e) });
        } finally {
            setLooking(false);
        }
    };

    return (
        <>
            <ScanBar id="goods-in-scan" inputRef={scanRef} label="Scan a PO number or container reference" busy={looking || open.isPending} onScan={(c) => void onScan(c)} />
            {message && <Notice tone={message.tone}>{message.text}</Notice>}

            {canReceive && (
                <section aria-labelledby="manual-heading" className="rounded-xl border border-slate-300 bg-white p-4">
                    <h2 id="manual-heading" className="text-xl font-semibold">
                        No paperwork?
                    </h2>
                    <p className="mt-1 text-base text-slate-600">A manual receipt is flagged for purchasing to match to an order.</p>
                    <div className="mt-3 flex flex-wrap items-end gap-3">
                        <div>
                            <label htmlFor="manual-location" className="block text-base font-medium">
                                Location
                            </label>
                            <select
                                id="manual-location"
                                value={locationCode}
                                onChange={(e) => setLocationCode(e.target.value)}
                                className={cn(FIELD, 'mt-1 rounded-md border border-slate-400 bg-white px-3')}
                            >
                                {locations.map((l) => (
                                    <option key={l.code} value={l.code}>
                                        {l.code} — {l.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} disabled={locationCode === '' || open.isPending} onClick={() => openFrom({ source: 'manual', location_code: locationCode })}>
                            Start manual receipt
                        </Button>
                    </div>
                </section>
            )}

            <section aria-labelledby="open-heading">
                <h2 id="open-heading" className="mb-2 text-xl font-semibold">
                    Open receipts
                </h2>
                {openReceipts.length === 0 ? (
                    <p className="text-base text-slate-600">None open.</p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {openReceipts.map((r) => (
                            <li key={r.id}>
                                <Button type="button" variant="outline" className="h-auto min-h-12 w-full justify-between bg-white px-4 py-3 text-left text-base" onClick={() => onOpened(r.id)}>
                                    <span>
                                        <span className={cn('block font-semibold', MONO)}>{r.reference ?? 'Manual receipt'}</span>
                                        <span className="block text-sm text-slate-600">
                                            {r.location_code} · {r.line_count} {r.line_count === 1 ? 'line' : 'lines'} · opened {formatTime(r.opened_at)}
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

// --- 2–7. Receive into an open receipt -----------------------------------

interface EntryTarget {
    sku: ReceivingSku;
    expected: ExpectedLine | null;
    packCode: string | null;
}

function ReceiptPanel({ receiptId, props, onLeave }: { receiptId: Ulid; props: GoodsInProps; onLeave: () => void }) {
    const receiptQuery = useReceipt(receiptId, props.receipt);
    const receipt = receiptQuery.data;
    const scanRef = useRef<HTMLInputElement>(null);
    const [entry, setEntry] = useState<EntryTarget | null>(null);
    const [choices, setChoices] = useState<EntryTarget[] | null>(null);
    const [pendingBin, setPendingBin] = useState<string | null>(null);
    const [message, setMessage] = useState<{ tone: 'error' | 'ok' | 'info'; text: string } | null>(null);
    const [closing, setClosing] = useState(false);
    const [looking, setLooking] = useState(false);
    const [horizonDays, setHorizonDays] = useState(props.default_horizon_days);

    const editable = props.can_receive && receipt?.status === 'open';
    useScanFocus(scanRef, entry === null && !closing);

    const refocusScan = () => window.setTimeout(() => scanRef.current?.focus(), 0);

    const startEntry = (target: EntryTarget) => {
        setChoices(null);
        setMessage(null);
        setEntry(target);
    };

    const targetsForSku = (sku: ReceivingSku, packCode: string | null): EntryTarget[] => {
        if (receipt === undefined) {
            return [];
        }
        if (receipt.source === 'manual') {
            return [{ sku, expected: null, packCode }];
        }

        return receipt.expected_lines.filter((l) => l.sku?.id === sku.id).map((l) => ({ sku, expected: l, packCode: packCode ?? l.pack_code }));
    };

    const onScan = async (code: string) => {
        if (receipt === undefined) {
            return;
        }
        setMessage(null);
        setLooking(true);
        try {
            const found = await lookupCode(code, receipt.id);
            if (found.horizon_days !== undefined) {
                setHorizonDays(found.horizon_days);
            }
            switch (found.kind) {
                case 'sku': {
                    if (!editable) {
                        setMessage({ tone: 'info', text: `${found.sku.sku_code} — this receipt is ${receipt.status === 'open' ? 'read-only for you' : 'closed'}.` });
                        break;
                    }
                    const targets = targetsForSku(found.sku, found.pack_code);
                    if (targets.length === 0) {
                        setMessage({ tone: 'error', text: `${found.sku.sku_code} is not on ${receipt.reference ?? 'this receipt'}. Check the delivery, or receive it on a manual receipt for purchasing to match.` });
                    } else if (targets.length === 1) {
                        startEntry(targets[0]);
                    } else {
                        setChoices(targets);
                    }
                    break;
                }
                case 'bin':
                    setPendingBin(found.bin_code);
                    setMessage({ tone: 'info', text: `Bin ${found.bin_code} will be used for the next entry.` });
                    break;
                case 'unknown':
                    if (editable && receipt.source === 'manual' && found.candidates.length > 0) {
                        setChoices(found.candidates.map((sku) => ({ sku, expected: null, packCode: null })));
                        setMessage({ tone: 'info', text: `${found.code} was not recognised. Did you mean one of these?` });
                    } else {
                        setMessage({ tone: 'error', text: `${found.code} was not recognised. It has been logged for the catalogue team — search by SKU code instead.` });
                    }
                    break;
                default:
                    setMessage({ tone: 'info', text: `${found.reference} is a ${found.kind === 'container' ? 'container' : 'purchase order'}. Close this receipt before starting another.` });
            }
        } catch (e) {
            setMessage({ tone: 'error', text: describe(e) });
        } finally {
            setLooking(false);
        }
    };

    if (receiptQuery.isPending) {
        return <Notice tone="info">Loading receipt…</Notice>;
    }

    if (receipt === undefined) {
        return (
            <>
                <Notice tone="error">{describe(receiptQuery.error)}</Notice>
                <Button type="button" variant="outline" className={TARGET} onClick={onLeave}>
                    Back to goods in
                </Button>
            </>
        );
    }

    return (
        <>
            <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white p-4">
                <div>
                    <p className="text-sm uppercase tracking-wide text-slate-600">{receipt.source === 'manual' ? 'Manual receipt' : receipt.source === 'container' ? 'Container' : 'Purchase order'}</p>
                    <p className={cn('text-2xl font-bold', MONO)}>{receipt.reference ?? 'No reference'}</p>
                    <p className="text-base text-slate-600">
                        {receipt.location.code} · {receipt.status === 'open' ? `opened ${formatTime(receipt.opened_at)}` : `closed ${formatTime(receipt.closed_at ?? receipt.opened_at)}`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-3">
                    {editable && !closing && entry === null && (
                        <Button type="button" variant="outline" className={cn(TARGET, 'px-5')} onClick={() => setClosing(true)}>
                            Close receipt
                        </Button>
                    )}
                    <Button type="button" variant="ghost" className={cn(TARGET, 'px-5')} onClick={onLeave}>
                        {receipt.status === 'open' ? 'Leave open' : 'Next receipt'}
                    </Button>
                </div>
            </section>

            {closing ? (
                <ClosePanel receipt={receipt} reasons={props.variance_reasons} onCancel={() => { setClosing(false); refocusScan(); }} onClosed={() => setClosing(false)} />
            ) : entry !== null ? (
                <EntryForm
                    key={`${entry.sku.id}:${entry.expected?.po_number ?? ''}:${entry.expected?.line_no ?? ''}`}
                    receiptId={receipt.id}
                    manual={receipt.source === 'manual'}
                    target={entry}
                    initialBin={pendingBin}
                    horizonDays={horizonDays}
                    onBooked={(text) => {
                        setEntry(null);
                        setPendingBin(null);
                        setMessage({ tone: 'ok', text });
                        refocusScan();
                    }}
                    onCancel={() => {
                        setEntry(null);
                        refocusScan();
                    }}
                />
            ) : (
                <>
                    {editable && <ScanBar id="goods-in-scan" inputRef={scanRef} label="Scan a case, SKU barcode, SKU code or bin" busy={looking} onScan={(c) => void onScan(c)} />}
                    {message && <Notice tone={message.tone}>{message.text}</Notice>}
                    {choices && <Choices choices={choices} onChoose={startEntry} onCancel={() => { setChoices(null); refocusScan(); }} />}
                </>
            )}

            {receipt.expected_lines.length > 0 && (
                <ExpectedTable lines={receipt.expected_lines} canStart={editable && entry === null && !closing} onStart={(l) => l.sku && startEntry({ sku: l.sku, expected: l, packCode: l.pack_code })} />
            )}
            <ReceivedList receipt={receipt} />
        </>
    );
}

function Choices({ choices, onChoose, onCancel }: { choices: EntryTarget[]; onChoose: (t: EntryTarget) => void; onCancel: () => void }) {
    const first = useRef<HTMLButtonElement>(null);
    useEffect(() => first.current?.focus(), []);

    return (
        <section aria-labelledby="choices-heading" className="rounded-xl border-2 border-slate-800 bg-white p-4" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
            <h2 id="choices-heading" className="mb-3 text-xl font-semibold">
                Which one?
            </h2>
            <ul className="space-y-2">
                {choices.map((c, i) => (
                    <li key={`${c.sku.id}:${c.expected?.po_number ?? ''}:${c.expected?.line_no ?? ''}`}>
                        <Button ref={i === 0 ? first : undefined} type="button" variant="outline" className="h-auto min-h-12 w-full justify-start px-4 py-3 text-left text-base" onClick={() => onChoose(c)}>
                            <span className={MONO}>{c.sku.sku_code}</span>
                            <span className="text-slate-600">{c.sku.name}</span>
                            {c.expected && (
                                <span className="ml-auto text-sm text-slate-600">
                                    {c.expected.po_number} line {c.expected.line_no} · {c.expected.outstanding_base_qty} units outstanding
                                </span>
                            )}
                        </Button>
                    </li>
                ))}
            </ul>
            <Button type="button" variant="ghost" className={cn(TARGET, 'mt-3')} onClick={onCancel}>
                Cancel (Esc)
            </Button>
        </section>
    );
}

function ExpectedTable({ lines, canStart, onStart }: { lines: ExpectedLine[]; canStart: boolean; onStart: (l: ExpectedLine) => void }) {
    return (
        <section aria-labelledby="expected-heading" className="overflow-x-auto rounded-xl border border-slate-300 bg-white">
            <h2 id="expected-heading" className="px-4 pt-4 text-xl font-semibold">
                Expected
            </h2>
            <table className="mt-2 w-full text-left text-base">
                <thead className="border-b border-slate-300 text-sm uppercase tracking-wide text-slate-600">
                    <tr>
                        <th scope="col" className="px-4 py-2">PO line</th>
                        <th scope="col" className="px-4 py-2">SKU</th>
                        <th scope="col" className="px-4 py-2">Ordered</th>
                        <th scope="col" className="px-4 py-2 text-right">Received</th>
                        <th scope="col" className="px-4 py-2 text-right">Outstanding</th>
                        <th scope="col" className="px-4 py-2"><span className="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                    {lines.map((l) => {
                        const done = l.outstanding_base_qty === 0;

                        return (
                            <tr key={`${l.po_number}:${l.line_no}`} className={cn(done && 'bg-emerald-50')}>
                                <td className={cn('px-4 py-2', MONO)}>
                                    {l.po_number}/{l.line_no}
                                </td>
                                <td className="px-4 py-2">
                                    <span className={cn('block font-semibold', MONO)}>{l.sku?.sku_code}</span>
                                    <span className="block text-sm text-slate-600">{l.sku?.name}</span>
                                </td>
                                <td className="px-4 py-2">{packEquivalent(l.ordered_pack_qty, l.pack_label ?? 'packs', l.pack_base_units)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{l.received_base_qty.toLocaleString('en-GB')}</td>
                                <td className="px-4 py-2 text-right font-semibold tabular-nums">{done ? <Check className="ml-auto size-5 text-emerald-700" aria-label="Complete" /> : l.outstanding_base_qty.toLocaleString('en-GB')}</td>
                                <td className="px-4 py-2 text-right">
                                    {canStart && l.sku && (
                                        <Button type="button" variant="outline" className={cn(TARGET, 'px-4')} onClick={() => onStart(l)}>
                                            Receive
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </section>
    );
}

function ReceivedList({ receipt }: { receipt: GoodsReceipt }) {
    return (
        <section aria-labelledby="received-heading" className="rounded-xl border border-slate-300 bg-white p-4">
            <h2 id="received-heading" className="text-xl font-semibold">
                Booked on this receipt
            </h2>
            {receipt.lines.length === 0 ? (
                <p className="mt-2 text-base text-slate-600">Nothing yet.</p>
            ) : (
                <ul className="mt-2 divide-y divide-slate-200">
                    {receipt.lines.map((l) => (
                        <li key={l.key} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-2 text-base">
                            <span className={cn('font-semibold', MONO)}>{l.sku_code}</span>
                            <span>
                                {l.pack_qty} × {l.pack_label} = <strong>{l.base_qty.toLocaleString('en-GB')}</strong> units
                            </span>
                            {l.batch_code && (
                                <span>
                                    Batch <span className={MONO}>{l.batch_code}</span>
                                    {l.expires_on && <> · exp {l.expires_on}</>}
                                </span>
                            )}
                            {l.serial_count > 0 && <span>{l.serial_count} serials</span>}
                            {l.bin_code && (
                                <span>
                                    Bin <span className={MONO}>{l.bin_code}</span>
                                </span>
                            )}
                            {!l.costed && <span className="rounded bg-amber-100 px-2 text-sm text-amber-900">No cost — flagged for purchasing</span>}
                            <span className="ml-auto text-sm text-slate-600">{formatTime(l.received_at)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

// --- Steps 3–6: the entry itself -----------------------------------------

function EntryForm({
    receiptId,
    manual,
    target,
    initialBin,
    horizonDays,
    onBooked,
    onCancel,
}: {
    receiptId: Ulid;
    manual: boolean;
    target: EntryTarget;
    initialBin: string | null;
    horizonDays: number;
    onBooked: (message: string) => void;
    onCancel: () => void;
}) {
    const { sku, expected } = target;
    const receive = useReceiveLine(receiptId);
    const formRef = useRef<HTMLFormElement>(null);
    const idempotency = useRef<{ fingerprint: string; key: string } | null>(null);

    const packs = sku.packs;
    const [packCode, setPackCode] = useState<string>(target.packCode ?? expected?.pack_code ?? packs[0]?.code ?? '');
    const [packQtyRaw, setPackQtyRaw] = useState('');
    const [batchCode, setBatchCode] = useState('');
    const [expiresOn, setExpiresOn] = useState(sku.expiry_prefill ?? '');
    const [serialsRaw, setSerialsRaw] = useState('');
    const [binCode, setBinCode] = useState(initialBin ?? '');
    const [costRaw, setCostRaw] = useState('');
    const [expiryConfirmed, setExpiryConfirmed] = useState(false);
    const [errors, setErrors] = useState<ApiErrorDetail[]>([]);
    const [formError, setFormError] = useState<string | null>(null);

    const batch = tracksBatch(sku.tracking_mode);
    const serial = tracksSerial(sku.tracking_mode);
    const pack = packs.find((p) => p.code === packCode) ?? null;
    const packQty = parsePackQty(packQtyRaw);
    const baseQty = pack && packQty !== null ? baseQtyOf(packQty, pack.base_units) : 0;
    const serials = useMemo(() => (serial ? parseSerials(serialsRaw) : []), [serial, serialsRaw]);
    const duplicates = useMemo(() => duplicateSerials(serials), [serials]);
    const warnings = batch && /^\d{4}-\d{2}-\d{2}$/.test(expiresOn) ? expiryWarnings(expiresOn, londonToday(), horizonDays) : [];
    const costE4 = costRaw.trim() === '' ? null : poundsToE4(costRaw);

    const fieldError = (field: string) => errors.find((e) => e.field === field)?.message ?? null;

    useEffect(() => {
        formRef.current?.querySelector<HTMLElement>('[data-first-field]')?.focus();
    }, []);

    const problems: string[] = [];
    if (packQty === null) problems.push('Enter how many packs.');
    if (batch && batchCode.trim() === '') problems.push('Scan or enter the batch code.');
    if (batch && sku.requires_expiry && expiresOn.trim() === '') problems.push('Enter the expiry date.');
    if (warnings.length > 0 && !expiryConfirmed) problems.push('Confirm the expiry date.');
    if (serial && baseQty > 0 && serials.length !== baseQty) problems.push(`Capture ${baseQty} serials (${serials.length} so far).`);
    if (duplicates.length > 0) problems.push('Remove the duplicated serials.');
    if (manual && costRaw.trim() !== '' && costE4 === null) problems.push('Cost must be pounds with up to four decimal places.');

    const submit = () => {
        if (problems.length > 0 || pack === null || packQty === null || receive.isPending) {
            setFormError(problems[0] ?? null);
            return;
        }

        const input: ReceiveLineInput = {
            purchase_order_line: expected ? { po_number: expected.po_number, line_no: expected.line_no } : null,
            sku_id: sku.id,
            pack_code: pack.code,
            pack_qty: packQty,
            batch_code: batch ? batchCode.trim() : null,
            expires_on: batch && expiresOn.trim() !== '' ? expiresOn.trim() : null,
            serials: serial ? serials : [],
            bin_code: binCode.trim() === '' ? null : binCode.trim(),
            unit_cost_e4: manual ? costE4 : null,
            confirm_expiry: expiryConfirmed,
        };

        // Same entry, same key (a retry after a dropped response replays);
        // a changed entry gets a new key (06 §6).
        const fingerprint = entryFingerprint(input);
        if (idempotency.current?.fingerprint !== fingerprint) {
            idempotency.current = { fingerprint, key: crypto.randomUUID() };
        }

        setErrors([]);
        setFormError(null);
        receive.mutate(
            { input, idempotencyKey: idempotency.current.key },
            {
                onSuccess: (result) => onBooked(`${result.replayed ? 'Already booked' : 'Booked'}: ${packEquivalent(packQty, pack.label, pack.base_units)} of ${sku.sku_code}.`),
                onError: (e) => {
                    if (e instanceof ApiError) {
                        setErrors(e.details);
                        setFormError(e.message);
                        if (e.code === 'expiry_confirmation_required') {
                            setExpiryConfirmed(false);
                        }
                    } else {
                        setFormError(describe(e));
                    }
                },
            },
        );
    };

    /** Enter advances (a scan ends in Enter); Ctrl/⌘+Enter books; Esc cancels. */
    const onKeyDown = (e: ReactKeyboardEvent<HTMLFormElement>) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            onCancel();
            return;
        }
        if (e.key !== 'Enter') {
            return;
        }
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            submit();
            return;
        }
        const el = e.target as HTMLElement;
        if (el.tagName === 'TEXTAREA' || el.tagName === 'BUTTON') {
            return;
        }
        e.preventDefault();
        const fields = Array.from(formRef.current?.querySelectorAll<HTMLElement>('[data-entry-field]') ?? []).filter((f) => !(f as HTMLInputElement).disabled);
        const next = fields[fields.indexOf(el) + 1];
        if (next) {
            next.focus();
        } else {
            formRef.current?.querySelector<HTMLButtonElement>('#goods-in-book')?.focus();
        }
    };

    const importCsv = async (file: File | undefined) => {
        if (!file) {
            return;
        }
        const text = await file.text();
        setSerialsRaw((current) => (current.trim() === '' ? text : `${current.trimEnd()}\n${text}`));
    };

    return (
        <form
            ref={formRef}
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            onKeyDown={onKeyDown}
            className="space-y-5 rounded-xl border-2 border-slate-800 bg-white p-5"
            aria-labelledby="entry-heading"
            noValidate
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="entry-heading" className="text-2xl font-bold">
                    <span className={MONO}>{sku.sku_code}</span> <span className="font-normal text-slate-700">{sku.name}</span>
                </h2>
                {expected && (
                    <p className="text-base text-slate-700">
                        {expected.po_number} line {expected.line_no} · ordered {packEquivalent(expected.ordered_pack_qty, expected.pack_label ?? 'packs', expected.pack_base_units)} · <strong>{expected.outstanding_base_qty.toLocaleString('en-GB')} units outstanding</strong>
                    </p>
                )}
            </div>

            {/* Step 3: quantity in packs, base equivalent live (05.5 §4.2). */}
            <fieldset>
                <legend className="text-base font-semibold">Pack</legend>
                <div className="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Pack">
                    {packs.map((p) => (
                        <label key={p.code} className={cn('flex min-h-12 cursor-pointer items-center gap-2 rounded-lg border-2 px-4 text-base', p.code === packCode ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white')}>
                            <input type="radio" name="pack" value={p.code} checked={p.code === packCode} onChange={() => setPackCode(p.code)} className="size-5" data-entry-field={p.code === packCode ? true : undefined} />
                            {p.label} <span className="text-sm opacity-80">({p.base_units})</span>
                        </label>
                    ))}
                </div>
            </fieldset>

            <div>
                <label htmlFor="entry-qty" className="block text-base font-semibold">
                    How many {pack?.label ?? 'packs'}?
                </label>
                <Input
                    id="entry-qty"
                    data-first-field
                    data-entry-field
                    value={packQtyRaw}
                    onChange={(e) => setPackQtyRaw(e.target.value.replace(/\D/g, ''))}
                    inputMode="numeric"
                    autoComplete="off"
                    className={cn(FIELD, 'mt-1 w-48 text-2xl tabular-nums')}
                    aria-describedby="entry-qty-equivalent"
                    aria-invalid={fieldError('pack_qty') !== null}
                />
                <p id="entry-qty-equivalent" aria-live="polite" className="mt-2 text-xl font-semibold">
                    {pack && packQty !== null ? packEquivalent(packQty, pack.label, pack.base_units) : '—'}
                </p>
            </div>

            {/* Step 4: conditional capture (05.5 §4.3) — only the fields this SKU needs. */}
            {batch && (
                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label htmlFor="entry-batch" className="block text-base font-semibold">
                            Batch code <span className="font-normal text-red-700">required</span>
                        </label>
                        <Input id="entry-batch" data-entry-field value={batchCode} onChange={(e) => setBatchCode(e.target.value)} autoComplete="off" autoCapitalize="characters" spellCheck={false} className={cn(FIELD, 'mt-1', MONO)} aria-invalid={fieldError('batch_code') !== null} aria-describedby="entry-batch-error" />
                        <p id="entry-batch-error" className="mt-1 text-base text-red-700">{fieldError('batch_code')}</p>
                    </div>
                    <div>
                        <label htmlFor="entry-expiry" className="block text-base font-semibold">
                            Expiry {sku.requires_expiry ? <span className="font-normal text-red-700">required</span> : <span className="font-normal text-slate-600">optional</span>}
                        </label>
                        <Input
                            id="entry-expiry"
                            data-entry-field
                            value={expiresOn}
                            onChange={(e) => {
                                setExpiresOn(e.target.value);
                                setExpiryConfirmed(false);
                            }}
                            placeholder="YYYY-MM-DD"
                            inputMode="numeric"
                            autoComplete="off"
                            className={cn(FIELD, 'mt-1', MONO)}
                            aria-invalid={fieldError('expires_on') !== null}
                            aria-describedby="entry-expiry-help"
                        />
                        <p id="entry-expiry-help" className="mt-1 text-sm text-slate-600">
                            {sku.expiry_prefill ? `Pre-filled from shelf life (${sku.expiry_prefill}). Change it to match the label.` : 'As printed on the case.'}
                        </p>
                        {fieldError('expires_on') && <p className="mt-1 text-base text-red-700">{fieldError('expires_on')}</p>}
                    </div>
                    {warnings.length > 0 && (
                        <div role="alert" className="rounded-lg border-2 border-amber-500 bg-amber-50 p-4 text-base text-amber-950 sm:col-span-2">
                            <p className="flex items-center gap-2 font-semibold">
                                <AlertTriangle className="size-5" aria-hidden /> {warnings.map((w) => WARNING_TEXT[w]).join(' ')}
                            </p>
                            <label className="mt-3 flex min-h-12 cursor-pointer items-center gap-3">
                                <input type="checkbox" data-entry-field checked={expiryConfirmed} onChange={(e) => setExpiryConfirmed(e.target.checked)} className="size-6" />
                                The expiry date is correct — book it anyway
                            </label>
                        </div>
                    )}
                </div>
            )}

            {serial && (
                <div>
                    <label htmlFor="entry-serials" className="block text-base font-semibold">
                        Serials — one per unit
                    </label>
                    <textarea
                        id="entry-serials"
                        data-entry-field
                        value={serialsRaw}
                        onChange={(e) => setSerialsRaw(e.target.value)}
                        rows={6}
                        spellCheck={false}
                        autoCapitalize="characters"
                        className={cn('mt-1 w-full rounded-md border border-slate-400 p-3 text-lg', MONO)}
                        aria-describedby="entry-serials-count"
                        aria-invalid={fieldError('serials') !== null}
                    />
                    <div className="mt-1 flex flex-wrap items-center gap-4">
                        <p id="entry-serials-count" aria-live="polite" className={cn('text-base font-semibold', baseQty > 0 && serials.length === baseQty ? 'text-emerald-700' : 'text-slate-800')}>
                            {serials.length} of {baseQty || '—'} captured
                            {duplicates.length > 0 && <span className="text-red-700"> · duplicated: {duplicates.join(', ')}</span>}
                        </p>
                        <label className="inline-flex min-h-12 cursor-pointer items-center rounded-md border border-slate-400 px-4 text-base focus-within:ring-2 focus-within:ring-slate-900">
                            Import CSV manifest
                            <input type="file" accept=".csv,.txt,text/csv,text/plain" className="sr-only" onChange={(e) => void importCsv(e.target.files?.[0])} />
                        </label>
                    </div>
                    {fieldError('serials') && <p className="mt-1 text-base text-red-700">{fieldError('serials')}</p>}
                </div>
            )}

            {/* Step 5: put-away. */}
            <div className="grid gap-5 sm:grid-cols-2">
                <div>
                    <label htmlFor="entry-bin" className="block text-base font-semibold">
                        Bin <span className="font-normal text-slate-600">optional — scan the bin label</span>
                    </label>
                    <Input id="entry-bin" data-entry-field value={binCode} onChange={(e) => setBinCode(e.target.value)} autoComplete="off" autoCapitalize="characters" spellCheck={false} className={cn(FIELD, 'mt-1', MONO)} aria-invalid={fieldError('bin_code') !== null} />
                    {fieldError('bin_code') && <p className="mt-1 text-base text-red-700">{fieldError('bin_code')}</p>}
                </div>
                {manual && (
                    <div>
                        <label htmlFor="entry-cost" className="block text-base font-semibold">
                            Cost per unit, £ <span className="font-normal text-slate-600">optional</span>
                        </label>
                        <Input id="entry-cost" data-entry-field value={costRaw} onChange={(e) => setCostRaw(e.target.value)} inputMode="decimal" autoComplete="off" className={cn(FIELD, 'mt-1 tabular-nums')} aria-describedby="entry-cost-help" />
                        <p id="entry-cost-help" className="mt-1 text-sm text-slate-600">
                            Without a cost, margin on this stock is unknown and purchasing is asked to review it.
                        </p>
                    </div>
                )}
            </div>

            {formError && <Notice tone="error">{formError}</Notice>}

            {/* Step 6: confirm — one transaction (04 §7.1). */}
            <div className="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                <Button id="goods-in-book" type="submit" className={cn(TARGET, 'px-8 text-lg')} disabled={receive.isPending}>
                    <Check aria-hidden /> {receive.isPending ? 'Booking…' : baseQty > 0 ? `Book ${baseQty.toLocaleString('en-GB')} units` : 'Book in'}
                </Button>
                <Button type="button" variant="outline" className={cn(TARGET, 'px-6')} onClick={onCancel}>
                    <X aria-hidden /> Cancel
                </Button>
                <p className="text-sm text-slate-600">Enter moves to the next field · Ctrl+Enter books · Esc cancels</p>
            </div>
        </form>
    );
}

// --- Step 7: close, with variance (05.5 §4.4) ----------------------------

function ClosePanel({ receipt, reasons, onCancel, onClosed }: { receipt: GoodsReceipt; reasons: { value: string; label: string }[]; onCancel: () => void; onClosed: () => void }) {
    const close = useCloseReceipt(receipt.id);
    const touched = useMemo(() => new Set(receipt.lines.filter((l) => l.po_number !== null).map((l) => `${l.po_number}:${l.line_no}`)), [receipt.lines]);
    const variances = receipt.expected_lines.filter((l) => touched.has(`${l.po_number}:${l.line_no}`) && l.received_base_qty !== l.ordered_base_qty);
    const [decisions, setDecisions] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<ApiErrorDetail[]>([]);
    const [formError, setFormError] = useState<string | null>(null);
    const firstRef = useRef<HTMLSelectElement>(null);
    const confirmRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        (firstRef.current ?? confirmRef.current)?.focus();
    }, []);

    const REMAINDER = '__remainder_expected';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const missing = variances.filter((l) => !decisions[`${l.po_number}:${l.line_no}`]);
        if (missing.length > 0) {
            setFormError(`Choose a reason for ${missing.map((l) => `${l.po_number} line ${l.line_no}`).join(', ')}.`);
            return;
        }
        setFormError(null);
        close.mutate(
            variances.map((l) => {
                const decision = decisions[`${l.po_number}:${l.line_no}`];

                return { po_number: l.po_number, line_no: l.line_no, reason: decision === REMAINDER ? null : decision, remainder_expected: decision === REMAINDER };
            }),
            {
                onSuccess: onClosed,
                onError: (err) => {
                    setErrors(err instanceof ApiError ? err.details : []);
                    setFormError(describe(err));
                },
            },
        );
    };

    return (
        <form onSubmit={submit} onKeyDown={(e) => e.key === 'Escape' && onCancel()} className="space-y-4 rounded-xl border-2 border-slate-800 bg-white p-5" aria-labelledby="close-heading">
            <h2 id="close-heading" className="text-2xl font-bold">
                Close receipt
            </h2>
            {variances.length === 0 ? (
                <p className="text-base">Everything received on this receipt matches its purchase order lines.</p>
            ) : (
                <>
                    <p className="text-base">These lines were received short or over. Each needs a reason — that is what the supplier report is built from.</p>
                    <ul className="space-y-3">
                        {variances.map((l, i) => {
                            const key = `${l.po_number}:${l.line_no}`;
                            const short = l.received_base_qty < l.ordered_base_qty;
                            const serverError = errors.find((d) => d.field === `variances.${l.po_number}.${l.line_no}`);

                            return (
                                <li key={key} className="rounded-lg border border-slate-300 p-3">
                                    <label htmlFor={`variance-${key}`} className="block text-base">
                                        <span className={cn('font-semibold', MONO)}>
                                            {l.po_number}/{l.line_no} {l.sku?.sku_code}
                                        </span>{' '}
                                        — {l.received_base_qty.toLocaleString('en-GB')} received of {l.ordered_base_qty.toLocaleString('en-GB')} ordered ({short ? 'short' : 'over'})
                                    </label>
                                    <select
                                        id={`variance-${key}`}
                                        ref={i === 0 ? firstRef : undefined}
                                        value={decisions[key] ?? ''}
                                        onChange={(e) => setDecisions((d) => ({ ...d, [key]: e.target.value }))}
                                        className={cn(FIELD, 'mt-2 w-full rounded-md border border-slate-400 bg-white px-3')}
                                        aria-invalid={serverError !== undefined}
                                    >
                                        <option value="">Choose…</option>
                                        {short && <option value={REMAINDER}>Remainder expected on a later delivery</option>}
                                        {reasons.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </select>
                                    {serverError && <p className="mt-1 text-base text-red-700">{serverError.message}</p>}
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
            {formError && <Notice tone="error">{formError}</Notice>}
            <div className="flex flex-wrap gap-3">
                <Button ref={confirmRef} type="submit" className={cn(TARGET, 'px-8')} disabled={close.isPending}>
                    {close.isPending ? 'Closing…' : 'Close receipt'}
                </Button>
                <Button type="button" variant="outline" className={cn(TARGET, 'px-6')} onClick={onCancel}>
                    Keep receiving (Esc)
                </Button>
            </div>
        </form>
    );
}
