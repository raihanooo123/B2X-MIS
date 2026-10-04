/**
 * Returns (05.4 §7.3–7.5, §13): scan or type an RMA number; book the parcel
 * in line by line — no stock moves until inspection; inspect it (restock,
 * quarantine, write off, or send back; diminished value only with a
 * reason); settle it (refund, or repair or replacement for faulty goods
 * after 30 days); review proof of sending and problem reports; record a
 * bank refund; record a cancellation made by email or phone.
 * The refund deadline shown is the server's own (the earlier of the goods
 * arriving and the customer's upload).
 *
 * Booking in sends an Idempotency-Key (06 §6): a retried tap books once.
 */
import { Head, router } from '@inertiajs/react';
import { PackageOpen } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { WarehouseNavigation } from '@/components/warehouse/WarehouseNavigation';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FIELD, MONO, Notice, ScanBar, TARGET, describeError, useScanFocus } from '@/components/warehouse/scan';
import { apiRequest } from '@/lib/api/client';
import { cn } from '@/lib/utils';

interface ReturnLine {
    line_no: number;
    sku_code: string;
    name: string;
    requested_base_qty: number;
    received_base_qty: number;
    restocked_base_qty: number;
    quarantined_base_qty: number;
    written_off_base_qty: number;
    disposition: string;
    disposition_reason: string | null;
    diminished_value_minor: number;
    diminished_value_reason: string | null;
    batch_recovered: boolean;
    line_refund_net_minor: number;
    line_refund_tax_minor: number;
}

interface ReturnData {
    id: string;
    rma_number: string;
    status: string;
    return_reason: string;
    reason_detail: string | null;
    is_cancellation: boolean;
    within_reject_period: boolean;
    resolution_type: string | null;
    remedy_record: { customer_choice?: string; decisions?: { reason: string; resolution_type: string }[] } | null;
    refund: { net_minor: number; tax_minor: number; delivery_net_minor: number; delivery_tax_minor: number; gross_minor: number; method: 'card' | 'bank_transfer' | null; status: string | null };
    order_number: string | null;
    customer: string | null;
    return_method: string | null;
    return_by_date: string | null;
    goods_sent_at: string | null;
    received_at: string | null;
    refund_due_on: string | null;
    proof: { id: string; name: string; mime_type: string; url: string; uploaded_at: string; rejected: boolean }[];
    lines: ReturnLine[];
    can_receive: boolean;
    can_reject_proof: boolean;
    can_inspect: boolean;
    can_resolve: boolean;
    can_review: boolean;
    can_record_bank_refund: boolean;
}

interface ExpectedReturn {
    rma_number: string;
    return_by_date: string | null;
    has_proof: boolean;
    refund_due_on: string | null;
    we_collect: boolean;
}

interface ReturnsProps {
    selected: ReturnData | null;
    expected: ExpectedReturn[];
    to_review: { rma_number: string; reason: string; requested_at: string }[];
    to_settle: { rma_number: string; status: string; refund_due_on: string | null }[];
    can_record_cancellation: boolean;
}

/** "£12.34" from pence; integers only (CLAUDE.md invariant 1). */
function pounds(minor: number): string {
    const sign = minor < 0 ? '-' : '';
    const abs = Math.abs(minor);

    return `${sign}£${Math.floor(abs / 100).toLocaleString('en-GB')}.${String(abs % 100).padStart(2, '0')}`;
}

/** Pence from a typed "12.34" — integer arithmetic, no floats. Null when not a valid amount. */
function parsePence(text: string): number | null {
    const m = /^\s*(\d{1,7})(?:\.(\d{1,2}))?\s*$/.exec(text);
    if (!m) return null;

    return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'));
}

async function post(path: string, body: unknown, idempotent = false): Promise<void> {
    await apiRequest(path, { method: 'POST', body, headers: idempotent ? { 'Idempotency-Key': crypto.randomUUID() } : undefined });
}

function ukDate(ymd: string | null): string {
    if (ymd === null) return '—';
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

function open(rmaNumber: string | null) {
    router.visit(rmaNumber === null ? '/warehouse/returns' : `/warehouse/returns?rma=${encodeURIComponent(rmaNumber)}`, { preserveScroll: false });
}

export default function Returns({ selected, expected, to_review: toReview, to_settle: toSettle, can_record_cancellation: canRecordCancellation }: ReturnsProps) {
    return (
        <div className="min-h-screen bg-slate-100 pb-24 text-lg text-slate-900 antialiased">
            <Head title="Returns" />
            <header className="border-b border-slate-300 bg-white">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div className="flex items-center gap-3">
                        <PackageOpen className="size-7 text-slate-700" aria-hidden />
                        <h1 className="text-2xl font-bold">Returns</h1>
                    </div>
                    <AccountMenu />
                </div>
            </header>
            <WarehouseNavigation />
            <main className="mx-auto max-w-5xl space-y-6 px-4 pt-6">
                {selected === null ? (
                    <>
                        <ExpectedPanel expected={expected} />
                        <ShortList title="Problem reports to review" items={toReview.map((r) => ({ rma: r.rma_number, note: r.reason.replace('_', ' ') }))} />
                        <ShortList title="To inspect or settle" items={toSettle.map((r) => ({ rma: r.rma_number, note: `${r.status}${r.refund_due_on ? ` · refund by ${ukDate(r.refund_due_on)}` : ''}` }))} />
                        {canRecordCancellation && <RecordCancellation />}
                    </>
                ) : (
                    <ReturnPanel key={selected.id} rma={selected} />
                )}
            </main>
        </div>
    );
}

function ExpectedPanel({ expected }: { expected: ExpectedReturn[] }) {
    const scanRef = useRef<HTMLInputElement>(null);
    useScanFocus(scanRef, true);

    return (
        <>
            <ScanBar id="returns-scan" inputRef={scanRef} label="Scan or type a return number (RMA)" busy={false} onScan={(code) => open(code.toUpperCase())} />
            <section aria-labelledby="returns-expected">
                <h2 id="returns-expected" className="mb-2 text-xl font-semibold">
                    Waiting for goods
                </h2>
                {expected.length === 0 ? (
                    <p className="text-base text-slate-600">No returns expected.</p>
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {expected.map((r) => (
                            <li key={r.rma_number}>
                                <Button type="button" variant="outline" className="h-auto min-h-12 w-full justify-between bg-white px-4 py-3 text-left text-base" onClick={() => open(r.rma_number)}>
                                    <span>
                                        <span className={cn('block font-semibold', MONO)}>{r.rma_number}</span>
                                        <span className="block text-sm text-slate-600">
                                            {r.we_collect ? 'We collect' : `Send back by ${ukDate(r.return_by_date)}`}
                                            {r.has_proof && ' · proof given'}
                                            {r.refund_due_on && ` · refund by ${ukDate(r.refund_due_on)}`}
                                        </span>
                                    </span>
                                    <span aria-hidden>Open</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

function ReturnPanel({ rma }: { rma: ReturnData }) {
    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className={cn('text-2xl font-bold', MONO)}>{rma.rma_number}</h2>
                <Button type="button" variant="outline" className={TARGET} onClick={() => open(null)}>
                    Back to returns
                </Button>
            </div>
            <dl className="grid gap-x-6 gap-y-1 rounded-md border border-slate-300 bg-white p-4 text-base sm:grid-cols-2">
                <Fact label="Status" value={rma.status.replace('_', ' ')} />
                <Fact label="Order" value={rma.order_number ?? '—'} />
                <Fact label="Customer" value={rma.customer ?? '—'} />
                <Fact label="Return" value={rma.return_method === 'collection' ? 'We collect' : `Customer posts, by ${ukDate(rma.return_by_date)}`} />
                <Fact label="Proof of sending" value={rma.goods_sent_at ? new Date(rma.goods_sent_at).toLocaleString('en-GB') : 'None'} />
                <Fact label="Refund due by" value={ukDate(rma.refund_due_on)} />
            </dl>

            {rma.proof.length > 0 && (
                <section aria-labelledby="return-proof" className="space-y-2">
                    <h3 id="return-proof" className="text-xl font-semibold">
                        Proof of sending
                    </h3>
                    <ul className="space-y-1 text-base">
                        {rma.proof.map((p) => (
                            <li key={p.id} className={cn(p.rejected && 'text-slate-500')}>
                                <a href={p.url} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                                    {p.name}
                                </a>{' '}
                                <span className="text-sm text-slate-600">
                                    · uploaded {new Date(p.uploaded_at).toLocaleString('en-GB')}
                                    {p.rejected && ' · rejected'}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {rma.can_reject_proof && <RejectProof rma={rma} />}
                </section>
            )}

            {rma.reason_detail && <Notice tone="info">{`${rma.return_reason.replace('_', ' ')}: ${rma.reason_detail}`}</Notice>}
            {rma.can_review && <ReviewPanel rma={rma} />}
            {rma.can_receive ? <ReceiveForm rma={rma} /> : rma.can_inspect ? <InspectForm rma={rma} /> : <ReceivedLines rma={rma} />}
            {rma.can_resolve && <ResolvePanel rma={rma} />}
            {['resolved', 'partially_resolved'].includes(rma.status) && <RefundSummary rma={rma} />}
            {rma.can_record_bank_refund && <BankRefund rma={rma} />}
        </>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3 py-1">
            <dt className="text-slate-600">{label}</dt>
            <dd className="text-right font-medium">{value}</dd>
        </div>
    );
}

/** 05.4 §7.3: what arrived per line. No stock moves until inspection. */
function ReceiveForm({ rma }: { rma: ReturnData }) {
    const [qty, setQty] = useState<Record<number, string>>(Object.fromEntries(rma.lines.map((l) => [l.line_no, String(l.requested_base_qty)])));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const key = useRef<string>(crypto.randomUUID());

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await apiRequest(`/warehouse/returns/${rma.id}/receive`, {
                method: 'POST',
                body: { lines: rma.lines.map((l) => ({ line_no: l.line_no, received_base_qty: Number(qty[l.line_no] || 0) })) },
                headers: { 'Idempotency-Key': key.current },
            });
            router.reload();
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-3" noValidate>
            <h3 className="text-xl font-semibold">Book the parcel in</h3>
            {rma.lines.map((l) => (
                <div key={l.line_no} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-300 bg-white p-3">
                    <label htmlFor={`receive-${l.line_no}`} className="min-w-0">
                        <span className="block font-semibold">{l.name}</span>
                        <span className={cn('block text-sm text-slate-600', MONO)}>
                            {l.sku_code} · expected {l.requested_base_qty}
                        </span>
                    </label>
                    <Input
                        id={`receive-${l.line_no}`}
                        inputMode="numeric"
                        value={qty[l.line_no] ?? ''}
                        onChange={(e) => setQty({ ...qty, [l.line_no]: e.target.value.replace(/\D/g, '') })}
                        className={cn(FIELD, 'w-28 text-right')}
                        aria-label={`Units received of ${l.sku_code}`}
                    />
                </div>
            ))}
            {error && <Notice tone="error">{error}</Notice>}
            <p className="text-base text-slate-600">The goods go to inspection, not to stock.</p>
            <Button type="submit" className={cn(TARGET, 'w-full')} disabled={busy}>
                Book in {rma.rma_number}
            </Button>
        </form>
    );
}

function ReceivedLines({ rma }: { rma: ReturnData }) {
    return (
        <section className="space-y-2">
            <h3 className="text-xl font-semibold">Lines</h3>
            <ul className="space-y-1 text-base">
                {rma.lines.map((l) => (
                    <li key={l.line_no} className="flex justify-between gap-3 rounded-md border border-slate-300 bg-white p-3">
                        <span>
                            {l.name} <span className={cn('text-sm text-slate-600', MONO)}>{l.sku_code}</span>
                        </span>
                        <span>
                            {l.received_base_qty} of {l.requested_base_qty}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** 05.4 §13.5: reject invalid proof. The customer is told why and may upload again. */
function RejectProof({ rma }: { rma: ReturnData }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await apiRequest(`/warehouse/returns/${rma.id}/reject-proof`, { method: 'POST', body: { reason } });
            router.reload();
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3" noValidate>
            <label htmlFor="reject-reason" className="block text-base font-medium">
                Reject this proof (the customer is emailed the reason)
            </label>
            <Input id="reject-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} className={FIELD} placeholder="e.g. The photo does not show a tracking number" />
            {error && <Notice tone="error">{error}</Notice>}
            <Button type="submit" variant="outline" className={TARGET} disabled={busy || reason.trim().length < 5}>
                Reject proof
            </Button>
        </form>
    );
}

function ShortList({ title, items }: { title: string; items: { rma: string; note: string }[] }) {
    if (items.length === 0) return null;

    return (
        <section className="space-y-2">
            <h2 className="text-xl font-semibold">{title}</h2>
            <ul className="grid gap-2 sm:grid-cols-2">
                {items.map((i) => (
                    <li key={i.rma}>
                        <Button type="button" variant="outline" className="h-auto min-h-12 w-full justify-between bg-white px-4 py-3 text-left text-base" onClick={() => open(i.rma)}>
                            <span>
                                <span className={cn('block font-semibold', MONO)}>{i.rma}</span>
                                <span className="block text-sm text-slate-600">{i.note}</span>
                            </span>
                            <span aria-hidden>Open</span>
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** 05.4 §13.4: approve a problem report (we collect, at our cost) or reject it with a reason. */
function ReviewPanel({ rma }: { rma: ReturnData }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const act = async (path: string, body: unknown) => {
        setBusy(true);
        setError(null);
        try {
            await post(path, body);
            router.reload();
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <section className="space-y-2 rounded-md border border-slate-300 bg-white p-4">
            <h3 className="text-xl font-semibold">Review this report</h3>
            <p className="text-base text-slate-600">{rma.within_reject_period ? 'Reported within 30 days of delivery: if accepted, it is refunded in full.' : 'Reported more than 30 days after delivery: offer repair or replacement first.'}</p>
            {error && <Notice tone="error">{error}</Notice>}
            <Button type="button" className={cn(TARGET, 'w-full')} disabled={busy} onClick={() => act(`/warehouse/returns/${rma.id}/approve`, {})}>
                Accept — we collect at our cost
            </Button>
            <label htmlFor="review-reason" className="block text-base font-medium">
                Or reject, with the reason the customer is given
            </label>
            <Input id="review-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} className={FIELD} />
            <Button type="button" variant="outline" className={TARGET} disabled={busy || reason.trim().length < 5} onClick={() => act(`/warehouse/returns/${rma.id}/reject`, { reason })}>
                Reject report
            </Button>
        </section>
    );
}

interface InspectDraft {
    restock: string;
    quarantine: string;
    write_off: string;
    diminished: string;
    diminished_reason: string;
}

/** 05.4 §7.4, §13.5: split what arrived. Only restock moves stock. */
function InspectForm({ rma }: { rma: ReturnData }) {
    const [draft, setDraft] = useState<Record<number, InspectDraft>>(
        Object.fromEntries(rma.lines.map((l) => [l.line_no, { restock: String(l.received_base_qty), quarantine: '0', write_off: '0', diminished: '', diminished_reason: '' }])),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const set = (lineNo: number, key: keyof InspectDraft, value: string) => setDraft({ ...draft, [lineNo]: { ...draft[lineNo], [key]: value } });

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        const lines = [];
        for (const l of rma.lines) {
            const d = draft[l.line_no];
            const diminished = d.diminished.trim() === '' ? 0 : parsePence(d.diminished);
            if (diminished === null) {
                setError(`Line ${l.line_no}: enter the deduction in pounds, e.g. 5.00.`);
                return;
            }
            lines.push({ line_no: l.line_no, restock: Number(d.restock || 0), quarantine: Number(d.quarantine || 0), write_off: Number(d.write_off || 0), diminished_value_minor: diminished, diminished_value_reason: d.diminished_reason || null });
        }
        setBusy(true);
        setError(null);
        try {
            await post(`/warehouse/returns/${rma.id}/inspect`, { lines }, true);
            router.reload();
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-3" noValidate>
            <h3 className="text-xl font-semibold">Inspect</h3>
            {rma.lines.map((l) => {
                const d = draft[l.line_no];
                return (
                    <fieldset key={l.line_no} className="space-y-2 rounded-md border border-slate-300 bg-white p-3">
                        <legend className="px-1 font-semibold">
                            {l.name} <span className={cn('text-sm text-slate-600', MONO)}>{l.sku_code}</span> · {l.received_base_qty} received
                            {!l.batch_recovered && ' · batch unknown'}
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {(['restock', 'quarantine', 'write_off'] as const).map((k) => (
                                <label key={k} className="text-sm">
                                    {k === 'write_off' ? 'Write off' : k[0].toUpperCase() + k.slice(1)}
                                    <Input inputMode="numeric" value={d[k]} onChange={(e) => set(l.line_no, k, e.target.value.replace(/\D/g, ''))} className={FIELD} />
                                </label>
                            ))}
                        </div>
                        <p className="text-sm text-slate-600">Anything not restocked, quarantined or written off goes back to the customer, unrefunded (an unsealed hygiene item).</p>
                        {rma.is_cancellation && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <label className="text-sm">
                                    Diminished value (£, optional)
                                    <Input inputMode="decimal" value={d.diminished} onChange={(e) => set(l.line_no, 'diminished', e.target.value)} className={FIELD} />
                                </label>
                                <label className="text-sm">
                                    Reason (required with a deduction)
                                    <Input value={d.diminished_reason} onChange={(e) => set(l.line_no, 'diminished_reason', e.target.value)} maxLength={500} className={FIELD} />
                                </label>
                            </div>
                        )}
                    </fieldset>
                );
            })}
            {error && <Notice tone="error">{error}</Notice>}
            <Button type="submit" className={cn(TARGET, 'w-full')} disabled={busy}>
                Save inspection
            </Button>
        </form>
    );
}

/** 05.4 §13.6: settle — a refund, or for faulty goods after 30 days, repair or replacement. */
function ResolvePanel({ rma }: { rma: ReturnData }) {
    const choose = !rma.is_cancellation && !rma.within_reject_period;
    const [type, setType] = useState(choose ? (rma.status === 'resolved' ? 'credit_note' : rma.resolution_type ?? 'repair') : 'credit_note');
    const [basis, setBasis] = useState('');
    const [outcome, setOutcome] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            await post(`/warehouse/returns/${rma.id}/resolve`, { resolution_type: type, override_basis: basis || null, remedy_outcome: outcome || null, remedy_reason: reason || null }, true);
            router.reload();
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <section className="space-y-2 rounded-md border border-slate-300 bg-white p-4">
            <h3 className="text-xl font-semibold">Settle</h3>
            {rma.status === 'awaiting_goods' && <p className="text-base text-slate-600">The customer gave proof of sending: the refund is owed whether or not the parcel arrives.</p>}
            {choose && (
                <select value={type} onChange={(e) => setType(e.target.value)} className={cn(FIELD, 'w-full rounded-md border border-slate-300 bg-white px-2')} aria-label="Resolution">
                    <option value="repair">Repair</option>
                    <option value="replacement">Replacement</option>
                    <option value="credit_note">Refund</option>
                </select>
            )}
            {choose && <>
                <p>Customer choice: {rma.remedy_record?.customer_choice ?? 'Not recorded'}</p>
                {type === 'credit_note' ? <select aria-label="Repair or replacement outcome" value={outcome} onChange={(e) => setOutcome(e.target.value)} className={cn(FIELD, 'w-full rounded-md border px-2')}>
                    <option value="">Choose outcome before refunding</option><option value="failed">Repair/replacement failed</option><option value="refused">Repair/replacement refused</option>
                </select> : <select aria-label="Override basis" value={basis} onChange={(e) => setBasis(e.target.value)} className={cn(FIELD, 'w-full rounded-md border px-2')}>
                    <option value="">Follow customer choice</option><option value="impossible">Customer choice impossible</option><option value="disproportionate">Customer choice disproportionate</option>
                </select>}
                <label className="block">Reason for override or failed/refused remedy<textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={2000} className={cn(FIELD, 'w-full rounded-md border px-2')} /></label>
                {rma.remedy_record?.decisions?.map((d, i) => <p key={i}>{d.resolution_type}: {d.reason}</p>)}
            </>}
            {error && <Notice tone="error">{error}</Notice>}
            <Button type="button" className={cn(TARGET, 'w-full')} disabled={busy} onClick={submit}>
                {type === 'credit_note' ? 'Refund the customer' : `Settle by ${type}`}
            </Button>
        </section>
    );
}

function RefundSummary({ rma }: { rma: ReturnData }) {
    if (rma.resolution_type !== 'credit_note') {
        return <Notice tone="ok">{`Settled by ${rma.resolution_type}.`}</Notice>;
    }

    return (
        <dl className="grid gap-x-6 gap-y-1 rounded-md border border-slate-300 bg-white p-4 text-base sm:grid-cols-2">
            <Fact label="Goods (net)" value={pounds(rma.refund.net_minor)} />
            <Fact label="Delivery (net)" value={pounds(rma.refund.delivery_net_minor)} />
            <Fact label="VAT" value={pounds(rma.refund.tax_minor + rma.refund.delivery_tax_minor)} />
            <Fact label="Refund" value={pounds(rma.refund.gross_minor)} />
            <Fact label="Paid by" value={rma.refund.method === 'card' ? 'Card' : rma.refund.method === 'bank_transfer' ? 'Bank transfer' : '—'} />
            <Fact label="Refund status" value={rma.refund.status ?? '—'} />
        </dl>
    );
}

/** 05.4 §13.6: a refund paid by bank transfer — BACS orders, and refused card refunds. */
function BankRefund({ rma }: { rma: ReturnData }) {
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await post(`/warehouse/returns/${rma.id}/bank-refund`, { reference });
            router.reload();
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3" noValidate>
            <label htmlFor="bank-ref" className="block text-base font-medium">
                {rma.refund.status === 'failed' ? 'The card refund failed. Pay by bank transfer and record its reference' : 'Pay by bank transfer and record its reference'}
            </label>
            <Input id="bank-ref" value={reference} onChange={(e) => setReference(e.target.value)} maxLength={100} className={FIELD} />
            {error && <Notice tone="error">{error}</Notice>}
            <Button type="submit" className={TARGET} disabled={busy || reference.trim().length < 3}>
                Record bank refund of {pounds(rma.refund.gross_minor)}
            </Button>
        </form>
    );
}

/** 05.4 §13.3 (S6e): a cancellation the customer made by email or phone, with when they told us. */
function RecordCancellation() {
    const [orderNumber, setOrderNumber] = useState('');
    const [notifiedAt, setNotifiedAt] = useState('');
    const [lines, setLines] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        const parsed = lines
            .split(',')
            .map((p) => p.trim())
            .filter(Boolean)
            .map((p) => {
                const [lineNo, qty] = p.split('x').map((n) => Number(n.trim()));
                return { line_no: lineNo, pack_qty: qty };
            });
        setBusy(true);
        setError(null);
        try {
            const created = await apiRequest<{ data: { rma_number: string } }>('/warehouse/returns/cancellations', { method: 'POST', body: { order_number: orderNumber, notified_at: notifiedAt, lines: parsed } });
            open(created.data.rma_number);
        } catch (err) {
            setError(describeError(err));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-2 rounded-md border border-slate-300 bg-white p-4" noValidate>
            <h2 className="text-xl font-semibold">Record a cancellation made by email or phone</h2>
            <label className="block text-sm">
                Order number
                <Input value={orderNumber} onChange={(e) => setOrderNumber(e.target.value)} className={FIELD} />
            </label>
            <label className="block text-sm">
                When the customer told us (UK time)
                <Input type="datetime-local" value={notifiedAt} onChange={(e) => setNotifiedAt(e.target.value)} className={FIELD} />
            </label>
            <label className="block text-sm">
                Lines and packs, e.g. "1x2, 3x1" (line 1, 2 packs; line 3, 1 pack)
                <Input value={lines} onChange={(e) => setLines(e.target.value)} className={FIELD} />
            </label>
            {error && <Notice tone="error">{error}</Notice>}
            <Button type="submit" className={TARGET} disabled={busy || orderNumber.trim() === '' || notifiedAt === '' || lines.trim() === ''}>
                Record cancellation
            </Button>
        </form>
    );
}
