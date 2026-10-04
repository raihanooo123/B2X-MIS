/**
 * Returns (05.4 §7.3, §13.5): scan or type an RMA number, book the parcel
 * in line by line — no stock moves until inspection — and, for accounts,
 * review a consumer's proof of sending and reject it if it is invalid.
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
}

interface ReturnData {
    id: string;
    rma_number: string;
    status: string;
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
}

function ukDate(ymd: string | null): string {
    if (ymd === null) return '—';
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

function open(rmaNumber: string | null) {
    router.visit(rmaNumber === null ? '/warehouse/returns' : `/warehouse/returns?rma=${encodeURIComponent(rmaNumber)}`, { preserveScroll: false });
}

export default function Returns({ selected, expected }: ReturnsProps) {
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
                {selected === null ? <ExpectedPanel expected={expected} /> : <ReturnPanel key={selected.id} rma={selected} />}
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

            {rma.can_receive ? <ReceiveForm rma={rma} /> : <ReceivedLines rma={rma} />}
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
