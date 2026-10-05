/**
 * Order confirmation: the order number, its lines and totals, where it is
 * going, and what happens next. Every figure is the order's own snapshot
 * (CLAUDE.md invariant 4) — never re-priced — shown ex- or inc-VAT per
 * `display_mode`.
 *
 * A guest reaches it by the signed link in their emails (05.15 §6.2) and
 * may save their details as an account (§6.3). The offer reads the same
 * whether or not the email already has one.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { CheckCircle2, XCircle } from 'lucide-react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Checkbox, Field } from '@/components/auth/Field';
import { PASSWORD_HINT } from '@/components/auth/passwordHint';
import { clearPasswords } from '@/components/auth/passwordInputs';
import { storefrontLinks } from '@/lib/storefront/links';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { PaymentMethod } from '@/lib/api/checkout';
import { lineTotalMinor, packPrice, totalsRows, vatLabel, type DeliveryLine, type DisplayMode } from '@/lib/cart/display';
import { formatMinor } from '@/lib/money';
import { formatDateTime } from '@/lib/dateTime';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface OrderLineView {
    line_no: number;
    sku_code: string;
    name: string;
    pack_label: string;
    pack_qty: number;
    pack_base_units: number;
    base_qty: number;
    cancelled_base_qty: number;
    unit_price_net_e4: number;
    tax_rate_bp: number;
    line_net_minor: number;
    line_tax_minor: number;
    line_gross_minor: number;
}

interface ConfirmationProps {
    display_mode: DisplayMode;
    order: {
        id: string;
        order_number: string;
        placed_at: string | null;
        status: string;
        /** 05.4 §14: `replacement` — zero value, sent to replace faulty goods. */
        kind: 'sale' | 'replacement';
        /** The order a replacement replaces. */
        replaces: { order_number: string; url: string } | null;
        /** 05.4 §13.2: a consumer order not yet dispatched. */
        can_cancel: boolean;
        undispatched_cancellation: UndispatchedCancellationView | null;
        cancelled_at: string | null;
        refunds: { amount_minor: number; method: 'card' | 'bank_transfer'; status: 'refunded' | 'in_progress' }[];
        /** 05.4 §13.3: what may be cancelled on a dispatched consumer order; null otherwise. */
        cancellation: CancellationView | null;
        /** 05.4 §13.4: what may be reported as faulty, damaged or wrong; null otherwise. */
        problem: ProblemView | null;
        returns: ReturnView[];
        payment_status: string;
        /** 05.6 §7A: a collection's slot; for an unpaid pay-at-collection order, the cash due and the deadline. */
        collection: { status: string; slot: string | null; location: string | null; collected_at: string | null; cash_amount_minor: number | null; payment_due_by: string | null } | null;
        /** 02 §18; `prepay` for orders placed outside web checkout, null for orders from before the column. */
        payment_method: PaymentMethod | 'prepay' | null;
        customer_reference: string | null;
        /** The card payment, if any — brand and last four only (07 §6.4). */
        card_payment: { status: string; card_brand: string | null; card_last4: string | null } | null;
        subtotal_net_minor: number;
        spend_break_discount_minor: number;
        shipping_net_minor: number;
        shipping_tax_minor: number;
        /** Null for orders placed before carriage was rated (02 §20). */
        delivery: DeliveryLine | null;
        tax_minor: number;
        total_gross_minor: number;
        lines: OrderLineView[];
        delivery_address: {
            contact_name: string | null;
            company_name: string | null;
            line1: string;
            line2: string | null;
            city: string;
            county: string | null;
            postcode: string;
            country: string;
        } | null;
    };
    /** Present when opened by a guest's signed link (05.15 §6.2). */
    guest: {
        email: string;
        can_save_details: boolean;
        account_url: string;
        status: string | null;
    } | null;
    /** Where "Cancel order" posts, when this viewer may cancel (05.4 §13.2). */
    cancel_url: string | null;
    /** Where "Cancel items" posts (05.4 §13.3). */
    cancel_items_url: string | null;
    cancel_undispatched_items_url: string | null;
    /** Proof of sending posts to `{returns_url}/{return id}/proof` (05.4 §13.5). */
    returns_url: string | null;
    /** Where "Report a problem" posts (05.4 §13.4). */
    problems_url: string | null;
}

interface ProblemView {
    reasons: { value: string; label: string }[];
    lines: { line_no: number; sku_code: string; name: string; pack_label: string; reportable_pack_qty: number }[];
}

interface CancellationView {
    available: boolean;
    message: string | null;
    last_day: string | null;
    return_statement: string;
    lines: { line_no: number; sku_code: string; name: string; pack_label: string; returnable_pack_qty: number; eligible: boolean; refusal: string | null; notice: string | null }[];
}

interface UndispatchedCancellationView {
    trade: boolean;
    lines: {
        line_no: number;
        name: string;
        sku_code: string;
        pack_label: string;
        max_pack_qty: number;
        kept_base_qty: number;
        pack_base_units: number;
        applied_break_qty: number | null;
    }[];
}

interface ReturnView {
    id: string;
    rma_number: string;
    status: string;
    return_method: string | null;
    return_by_date: string | null;
    proof_sent_at: string | null;
    received_at: string | null;
    refund_due_on: string | null;
    accepts_proof: boolean;
    is_problem_report: boolean;
    resolution_type: string | null;
    refund_gross_minor: number;
    lines: { sku_code: string; name: string; pack_qty: number }[];
}

const BRANDS: Record<string, string> = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express', maestro: 'Maestro', discover: 'Discover', diners: 'Diners Club', jcb: 'JCB', unionpay: 'UnionPay' };

/** "Visa ending 4242" — never more of the card than that (07 §6.4). */
function cardDescription(card: NonNullable<ConfirmationProps['order']['card_payment']>): string {
    const brand = card.card_brand ? (BRANDS[card.card_brand] ?? card.card_brand) : 'Card';

    return card.card_last4 ? `${brand} ending ${card.card_last4}` : brand;
}

/** What the buyer should expect, by how they chose to pay. */
function nextSteps(order: ConfirmationProps['order']): string[] {
    if (order.kind === 'replacement' && order.status !== 'cancelled') {
        return [
            `This replaces faulty goods${order.replaces ? ` from order ${order.replaces.order_number}` : ''}, at no cost to you.`,
            'We are now picking it.',
            'We will email you when it is dispatched.',
        ];
    }

    if (order.status === 'cancelled') {
        if (order.refunds.length === 0) {
            return [order.card_payment ? 'The payment held on your card has been released. You have not been charged.' : 'No payment was taken for this order.'];
        }

        return order.refunds.map((r) =>
            r.method === 'card'
                ? `${formatMinor(r.amount_minor)} ${r.status === 'refunded' ? 'refunded' : 'being refunded'} to your card. Your bank may take a few days to show it.`
                : `${formatMinor(r.amount_minor)} will be refunded by bank transfer within 14 days. We will contact you for your bank details.`,
        );
    }

    const common = 'We will email you when your order is dispatched.';

    // 05.6 §7A.3: collect, and pay cash then, by the deadline.
    const collection = order.collection;
    if (collection !== null) {
        if (collection.status === 'collected') {
            return ['You collected this order.'];
        }
        const when = `Collect from ${collection.location ?? 'our counter'}${collection.slot ? `, ${collection.slot}` : ''} (UK time). Bring your order number.`;
        if (collection.cash_amount_minor !== null && collection.payment_due_by !== null) {
            return [
                when,
                `Pay ${formatMinor(collection.cash_amount_minor)} in cash when you collect — cash only.`,
                `If you have not collected and paid by ${formatDeadline(collection.payment_due_by)}, the order is cancelled and the goods released. Nothing will have been taken from you.`,
            ];
        }

        return [when, 'Your stock is reserved.'];
    }

    if (order.payment_status === 'paid' && order.card_payment) {
        return [`Payment of ${formatMinor(order.total_gross_minor)} taken from your ${cardDescription(order.card_payment)}.`, 'We are now picking your order.', common];
    }

    if (order.card_payment?.status === 'authorized') {
        return [
            `Your ${cardDescription(order.card_payment)} is authorised for ${formatMinor(order.total_gross_minor)}; the payment is being completed.`,
            'Your stock is reserved.',
            common,
        ];
    }

    if (order.payment_status === 'on_account' || order.payment_method === 'on_account') {
        return ['Your order is confirmed and will be invoiced on your account terms.', 'We are now picking your order.', common];
    }

    if (order.payment_method === 'bacs') {
        return [
            `Please pay ${formatMinor(order.total_gross_minor)} by bank transfer, quoting ${order.order_number} as the payment reference.`,
            'Your stock is reserved. We dispatch once your payment has cleared.',
            common,
        ];
    }

    if (order.payment_method === 'card') {
        return [`We will contact you to take card payment of ${formatMinor(order.total_gross_minor)}.`, 'Your stock is reserved. We dispatch once payment is taken.', common];
    }

    return ['Your order is confirmed and your stock is reserved.', 'Payment is due before dispatch unless you have account terms.', common];
}

export default function Confirmation({ display_mode: mode, order, guest, cancel_url: cancelUrl, cancel_items_url: cancelItemsUrl, cancel_undispatched_items_url: cancelUndispatchedItemsUrl, returns_url: returnsUrl, problems_url: problemsUrl }: ConfirmationProps) {
    const { display_timezone: timeZone, auth, flash } = usePage<SharedProps>().props;
    const cancelled = order.status === 'cancelled';
    const status = guest ? guest.status : (flash?.status ?? null);

    const address = order.delivery_address;

    return (
        <>
            <Head title={`Order ${order.order_number}`} />
            <div className="mx-auto max-w-6xl px-4 py-8">
                <header className="mb-6 flex flex-wrap items-center justify-end gap-x-4">
                    <AccountMenu />
                </header>

                <section className="mb-8 flex items-start gap-4 rounded-2xl border bg-muted/20 p-6">
                    {cancelled ? (
                        <XCircle className="mt-0.5 size-8 shrink-0 text-muted-foreground" aria-hidden />
                    ) : (
                        <CheckCircle2 className="mt-0.5 size-8 shrink-0 text-emerald-600" aria-hidden />
                    )}
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {cancelled
                                ? `Order ${order.order_number} is cancelled`
                                : order.kind === 'replacement'
                                  ? `Your replacement order ${order.order_number}`
                                  : guest
                                    ? `Your order ${order.order_number}`
                                    : 'Thank you — your order is placed'}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Order number <strong className="font-mono text-foreground">{order.order_number}</strong>
                            {order.placed_at && <> · {formatDateTime(order.placed_at, timeZone)}</>}
                            {order.customer_reference && <> · Your reference {order.customer_reference}</>}
                        </p>
                        {order.kind === 'replacement' && (
                            <p className="mt-2 inline-flex flex-wrap items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-900">
                                Replacement · No payment needed
                                {order.replaces && (
                                    <>
                                        {' · for order '}
                                        <Link href={order.replaces.url} className="underline underline-offset-2">
                                            {order.replaces.order_number}
                                        </Link>
                                    </>
                                )}
                            </p>
                        )}
                        {order.payment_status === 'paid' && !cancelled && (
                            <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800">
                                <CheckCircle2 className="size-3.5" aria-hidden /> Paid{order.card_payment && ` · ${cardDescription(order.card_payment)}`}
                            </p>
                        )}
                    </div>
                </section>

                <div className="grid gap-6 md:grid-cols-[1fr_280px]">
                    <section aria-label="Order lines" className="min-w-0">
                        <div className="overflow-x-auto rounded-2xl border bg-background">
                            <Table>
                                <TableHeader>
                                    <TableRow className="text-xs hover:bg-transparent">
                                        <TableHead className="h-8 w-10 pr-0 text-right">#</TableHead>
                                        <TableHead className="h-8">Product</TableHead>
                                        <TableHead className="h-8 text-right">Qty</TableHead>
                                        <TableHead className="h-8 text-right">Price ({vatLabel(mode)})</TableHead>
                                        <TableHead className="h-8 text-right">Line</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {order.lines.map((l) => (
                                        <TableRow key={l.line_no} className="text-[13px]">
                                            <TableCell className="w-10 py-2 pr-0 text-right align-top tabular-nums text-muted-foreground">{l.line_no}</TableCell>
                                            <TableCell className="py-2 align-top">
                                                <div className="font-medium leading-tight">{l.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    <span className="font-mono">{l.sku_code}</span> · {l.pack_label}
                                                </div>
                                                {l.cancelled_base_qty > 0 && <div className="text-xs text-muted-foreground">Cancelled: {Math.floor(l.cancelled_base_qty / l.pack_base_units)} pack(s)</div>}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap py-2 text-right align-top tabular-nums">{l.pack_qty.toLocaleString('en-GB')}</TableCell>
                                            <TableCell className="whitespace-nowrap py-2 text-right align-top tabular-nums">
                                                {packPrice(l.unit_price_net_e4, l.tax_rate_bp, l.pack_base_units, mode)}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap py-2 text-right align-top font-medium tabular-nums">{formatMinor(lineTotalMinor(l, mode) ?? 0)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <dl className="ml-auto mt-3 max-w-xs space-y-1.5 text-sm tabular-nums">
                            {totalsRows(order, mode, order.delivery ?? { status: 'rated', zone_name: null, method: null, shipping_net_minor: order.shipping_net_minor, shipping_tax_minor: order.shipping_tax_minor }).map((row) => (
                                <div key={row.label} className="flex justify-between gap-4">
                                    <dt className={cn(row.tone === 'strong' ? 'font-semibold' : 'text-muted-foreground')}>{row.label}</dt>
                                    <dd className={cn(row.tone === 'strong' && 'text-base font-semibold', row.tone === 'discount' && 'text-emerald-700', row.tone === 'muted' && 'text-muted-foreground')}>{row.value}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <aside className="space-y-6 text-sm">
                        <section>
                            <h2 className="mb-2 font-semibold">{cancelled ? 'Your money' : 'What happens next'}</h2>
                            <ol className="list-decimal space-y-1.5 pl-5 text-muted-foreground">
                                {nextSteps(order).map((step) => (
                                    <li key={step}>{step}</li>
                                ))}
                            </ol>
                        </section>

                        {status && (
                            <p role="status" className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-emerald-900">
                                {status}
                            </p>
                        )}

                        {guest?.can_save_details && !guest.status && <SaveDetails guest={guest} />}

                        {cancelUrl && order.can_cancel && <CancelOrder orderNumber={order.order_number} url={cancelUrl} />}

                        {cancelUndispatchedItemsUrl && order.undispatched_cancellation && <CancelUndispatchedItems cancellation={order.undispatched_cancellation} url={cancelUndispatchedItemsUrl} />}

                        {order.returns.length > 0 && <Returns returns={order.returns} returnsUrl={returnsUrl} />}

                        {cancelItemsUrl && order.cancellation && <CancelItems cancellation={order.cancellation} url={cancelItemsUrl} />}

                        {problemsUrl && order.problem && <ReportProblem problem={order.problem} url={problemsUrl} />}

                        {address && (
                            <section>
                                <h2 className="mb-2 font-semibold">Delivering to</h2>
                                <address className="not-italic leading-relaxed text-muted-foreground">
                                    {[address.contact_name, address.company_name, address.line1, address.line2, address.city, address.county, address.postcode, address.country]
                                        .filter(Boolean)
                                        .map((part, i) => (
                                            <span key={i} className="block">
                                                {part}
                                            </span>
                                        ))}
                                </address>
                            </section>
                        )}

                        <div className="flex flex-col gap-2">
                            <Button asChild className="h-11">
                                <Link href={storefrontLinks.continueShopping(auth?.company != null)}>Continue shopping</Link>
                            </Button>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

/**
 * 05.15 §6.3 "Save your details — set a password". The email is the
 * order's own. Once it is confirmed, the guest's orders join the account.
 */
function SaveDetails({ guest }: { guest: NonNullable<ConfirmationProps['guest']> }) {
    const form = useForm({ first_name: '', last_name: '', password: '', password_confirmation: '', terms: false });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const element = e.currentTarget;
        const values = new FormData(element);
        form.transform((data) => ({ ...data, password: String(values.get('password') ?? ''), password_confirmation: String(values.get('password_confirmation') ?? '') }));
        form.post(guest.account_url, {
            preserveScroll: true,
            onFinish: () => {
                form.reset('password', 'password_confirmation');
                clearPasswords(element);
            },
        });
    };

    return (
        <section className="space-y-3 rounded-md border p-4">
            <div>
                <h2 className="font-semibold">Save your details</h2>
                <p className="mt-1 text-muted-foreground">Set a password to track this order and check out faster next time. We will use {guest.email}.</p>
            </div>
            <form onSubmit={submit} className="space-y-3" noValidate>
                <Field label="First name" autoComplete="given-name" required value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} error={form.errors.first_name} />
                <Field label="Last name" autoComplete="family-name" required value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} error={form.errors.last_name} />
                <Field label="Password" type="password" name="password" autoComplete="new-password" required minLength={12} defaultValue="" error={form.errors.password} hint={PASSWORD_HINT} />
                <Field label="Confirm password" type="password" name="password_confirmation" autoComplete="new-password" required defaultValue="" error={form.errors.password_confirmation} />
                <Checkbox label="I accept the terms of sale." checked={form.data.terms} onChange={(v) => form.setData('terms', v)} error={form.errors.terms} />
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    Save my details
                </Button>
            </form>
        </section>
    );
}

/**
 * 05.4 §13.2: cancel the whole order before it is dispatched. Two steps, so
 * a stray tap does not cancel it; the server re-checks that nothing has
 * been dispatched meanwhile.
 */
function CancelOrder({ orderNumber, url }: { orderNumber: string; url: string }) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const cancel = () => {
        setProcessing(true);
        router.post(url, {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    return (
        <section className="space-y-2 rounded-md border p-4">
            <h2 className="font-semibold">Changed your mind?</h2>
            <p className="text-muted-foreground">You can cancel this order until we send it. You will get a full refund.</p>
            {confirming ? (
                <div className="flex flex-col gap-2">
                    <p className="font-medium">Cancel order {orderNumber}?</p>
                    <Button type="button" variant="destructive" className="h-11" disabled={processing} onClick={cancel}>
                        Yes, cancel my order
                    </Button>
                    <Button type="button" variant="outline" className="h-11" disabled={processing} onClick={() => setConfirming(false)}>
                        Keep my order
                    </Button>
                </div>
            ) : (
                <Button type="button" variant="outline" className="h-11 w-full" onClick={() => setConfirming(true)}>
                    Cancel order
                </Button>
            )}
        </section>
    );
}

/** 05.10 §2: whole packs not yet packed; the server locks and rechecks on submit. */
function CancelUndispatchedItems({ cancellation, url }: { cancellation: UndispatchedCancellationView; url: string }) {
    const [token, setToken] = useState(() => crypto.randomUUID());
    const form = useForm<{ lines: { line_no: number; pack_qty: number }[]; reason_detail: string }>({
        lines: cancellation.lines.map((line) => ({ line_no: line.line_no, pack_qty: 0 })),
        reason_detail: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const selected = form.data.lines.some((line) => line.pack_qty > 0);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(url, {
            preserveScroll: true,
            headers: { 'Idempotency-Key': token },
            onSuccess: () => {
                form.reset();
                setToken(crypto.randomUUID());
            },
        });
    };

    return (
        <section className="space-y-3 rounded-md border p-4">
            <h2 className="font-semibold">Cancel items before dispatch</h2>
            <form onSubmit={submit} className="space-y-3">
                {cancellation.lines.map((line, index) => {
                    const selectedLine = form.data.lines.find((item) => item.line_no === line.line_no);
                    const after = line.kept_base_qty - (selectedLine?.pack_qty ?? 0) * line.pack_base_units;
                    const belowBreak = cancellation.trade && line.applied_break_qty !== null && after > 0 && after < line.applied_break_qty;

                    return (
                        <div key={line.line_no} className="space-y-1">
                            <label htmlFor={`undispatched-${line.line_no}`} className="block font-medium">{line.name}</label>
                            <p className="text-xs text-muted-foreground">{line.sku_code} · {line.pack_label} · up to {line.max_pack_qty} pack(s)</p>
                            <input
                                id={`undispatched-${line.line_no}`}
                                type="number"
                                min={0}
                                max={line.max_pack_qty}
                                step={1}
                                value={selectedLine?.pack_qty ?? 0}
                                onChange={(event) => form.setData('lines', form.data.lines.map((item) => item.line_no === line.line_no ? { ...item, pack_qty: Number(event.target.value) } : item))}
                                className="h-11 w-24 rounded-md border border-input bg-transparent px-2"
                            />
                            {belowBreak && <p className="text-xs text-red-700">Keep at least {line.applied_break_qty} units at this price break, or cancel the whole line.</p>}
                            {errors[`lines.${index}.pack_qty`] && <p className="text-xs text-red-700">{errors[`lines.${index}.pack_qty`]}</p>}
                        </div>
                    );
                })}
                {cancellation.trade && (
                    <div>
                        <label htmlFor="cancellation-reason" className="block font-medium">Reason</label>
                        <input id="cancellation-reason" value={form.data.reason_detail} onChange={(event) => form.setData('reason_detail', event.target.value)} maxLength={500} className="h-11 w-full rounded-md border border-input bg-transparent px-2" />
                        {errors.reason_detail && <p className="text-xs text-red-700">{errors.reason_detail}</p>}
                    </div>
                )}
                {errors.lines && <p role="alert" className="text-xs text-red-700">{errors.lines}</p>}
                <Button type="submit" variant="outline" className="h-11 w-full" disabled={!selected || form.processing || (cancellation.trade && !form.data.reason_detail.trim())}>
                    Cancel selected packs
                </Button>
            </form>
        </section>
    );
}

/** "26 October 2026" from a Y-m-d date, without a timezone shift. */
function longDate(ymd: string): string {
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
}

const RETURN_STATUS: Record<string, string> = {
    requested: 'We are reviewing your report',
    awaiting_goods: 'Waiting for your return',
    received: 'Received — being checked',
    inspected: 'Checked — refund on its way',
    resolved: 'Refunded',
    partially_resolved: 'Partly refunded',
    not_received: 'Not received in time',
    cancelled: 'Withdrawn',
    rejected: 'Not accepted',
};

/** The cancellations already made on this order (05.4 §13.3), with proof of sending (§13.5). */
function Returns({ returns, returnsUrl }: { returns: ReturnView[]; returnsUrl: string | null }) {
    return (
        <section className="space-y-3">
            <h2 className="font-semibold">Your cancellations and returns</h2>
            {returns.map((r) => (
                <div key={r.rma_number} className="rounded-md border p-3">
                    <p className="font-medium">
                        <span className="font-mono">{r.rma_number}</span> · {RETURN_STATUS[r.status] ?? r.status}
                    </p>
                    <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                        {r.lines.map((l) => (
                            <li key={l.sku_code}>
                                {l.pack_qty} × {l.name}
                            </li>
                        ))}
                    </ul>
                    {r.status === 'awaiting_goods' && r.return_by_date && r.return_method !== 'collection' && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Send back by {longDate(r.return_by_date)}, with {r.rma_number} on the parcel.
                        </p>
                    )}
                    {r.status === 'awaiting_goods' && r.return_method === 'collection' && <p className="mt-1 text-xs text-muted-foreground">We will contact you to collect them.</p>}
                    {r.proof_sent_at && <p className="mt-1 text-xs text-emerald-800">We have your proof of sending.</p>}
                    {r.resolution_type === 'credit_note' && r.refund_gross_minor > 0 && <p className="mt-1 text-xs text-emerald-800">Refund of {formatMinor(r.refund_gross_minor)}.</p>}
                    {r.status === 'resolved' && r.resolution_type === 'repair' && <p className="mt-1 text-xs text-muted-foreground">We are repairing the items and will send them back.</p>}
                    {r.status === 'resolved' && r.resolution_type === 'replacement' && <p className="mt-1 text-xs text-muted-foreground">We are sending you replacements.</p>}
                    {r.refund_due_on && !['resolved', 'partially_resolved'].includes(r.status) && (
                        <p className="mt-1 text-xs text-muted-foreground">We will refund you by {longDate(r.refund_due_on)}.</p>
                    )}
                    {returnsUrl && r.accepts_proof && <ProofUpload url={`${returnsUrl}/${r.id}/proof`} hasProof={r.proof_sent_at !== null} />}
                </div>
            ))}
        </section>
    );
}

/**
 * 05.4 §13.3 "Cancel items": the customer picks how many of each line to
 * cancel. Lines that cannot be cancelled say why; sealed hygiene items say
 * they must come back sealed. The server re-checks everything.
 */
function CancelItems({ cancellation, url }: { cancellation: CancellationView; url: string }) {
    const form = useForm<{ lines: { line_no: number; pack_qty: number }[] }>({
        lines: cancellation.lines.filter((l) => l.eligible).map((l) => ({ line_no: l.line_no, pack_qty: 0 })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const chosen = form.data.lines.some((l) => l.pack_qty > 0);

    if (!cancellation.available) {
        return cancellation.message ? (
            <section className="space-y-1 rounded-md border p-4">
                <h2 className="font-semibold">Cancel items</h2>
                <p className="text-muted-foreground">{cancellation.message}</p>
            </section>
        ) : null;
    }

    const setQty = (lineNo: number, qty: number) =>
        form.setData(
            'lines',
            form.data.lines.map((l) => (l.line_no === lineNo ? { ...l, pack_qty: qty } : l)),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <section className="space-y-3 rounded-md border p-4">
            <div>
                <h2 className="font-semibold">Cancel items</h2>
                {cancellation.last_day && <p className="mt-1 text-muted-foreground">You can cancel any of these until {longDate(cancellation.last_day)}.</p>}
            </div>
            <form onSubmit={submit} className="space-y-3" noValidate>
                {cancellation.lines.map((l) => {
                    const value = form.data.lines.find((x) => x.line_no === l.line_no)?.pack_qty ?? 0;
                    const error = errors[`lines.${l.line_no}`];

                    return (
                        <div key={l.line_no} className="space-y-1">
                            <div className="flex items-start justify-between gap-3">
                                <label htmlFor={`cancel-line-${l.line_no}`} className="min-w-0">
                                    <span className="block font-medium leading-tight">{l.name}</span>
                                    <span className="block text-xs text-muted-foreground">
                                        <span className="font-mono">{l.sku_code}</span> · {l.pack_label}
                                    </span>
                                </label>
                                {l.eligible && (
                                    <select
                                        id={`cancel-line-${l.line_no}`}
                                        value={value}
                                        onChange={(e) => setQty(l.line_no, Number(e.target.value))}
                                        className="h-10 shrink-0 rounded-md border border-input bg-transparent px-2"
                                    >
                                        {Array.from({ length: l.returnable_pack_qty + 1 }, (_, n) => (
                                            <option key={n} value={n}>
                                                {n}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                            {l.refusal && <p className="text-xs text-muted-foreground">{l.refusal}</p>}
                            {l.notice && <p className="text-xs text-amber-800">{l.notice}</p>}
                            {error && <p className="text-xs text-red-700">{error}</p>}
                        </div>
                    );
                })}
                {errors.lines && <p className="text-xs text-red-700">{errors.lines}</p>}
                <p className="text-xs text-muted-foreground">{cancellation.return_statement} We refund within 14 days of receiving the items, or of your proof that you sent them, whichever is earlier.</p>
                <Button type="submit" variant="outline" className="h-11 w-full" disabled={!chosen || form.processing}>
                    Cancel these items
                </Button>
            </form>
        </section>
    );
}

/**
 * 05.4 §13.5 "I've sent it back": a photo or PDF of the proof of postage or
 * tracking. The refund deadline runs from the moment it is uploaded.
 */
function ProofUpload({ url, hasProof }: { url: string; hasProof: boolean }) {
    const form = useForm<{ proof: File | null }>({ proof: null });
    const id = `proof-${url.split('/').slice(-2, -1)[0]}`;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="mt-2 space-y-1.5" noValidate>
            <label htmlFor={id} className="block text-xs font-medium">
                {hasProof ? 'Add more proof of sending' : "I've sent it back — upload proof of postage or tracking"}
            </label>
            <input
                id={id}
                type="file"
                accept=".jpg,.jpeg,.png,.webp,.pdf"
                onChange={(e) => form.setData('proof', e.target.files?.[0] ?? null)}
                className="block w-full text-xs file:mr-2 file:rounded file:border file:border-input file:bg-background file:px-2 file:py-1"
            />
            {form.errors.proof && <p className="text-xs text-red-700">{form.errors.proof}</p>}
            <Button type="submit" variant="outline" size="sm" className="h-9" disabled={form.data.proof === null || form.processing}>
                Upload proof
            </Button>
        </form>
    );
}

/**
 * 05.15 §7.3 "Report a problem" (05.4 §13.4): faulty, damaged or wrong
 * goods. Never limited by the 14-day cancellation period. A handler reviews
 * it; within 30 days of delivery it is refunded in full.
 */
function ReportProblem({ problem, url }: { problem: ProblemView; url: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ reason: string; detail: string; customer_choice: string; lines: { line_no: number; pack_qty: number }[]; photos: File[] }>({
        reason: problem.reasons[0]?.value ?? 'faulty',
        customer_choice: '',
        detail: '',
        lines: problem.lines.map((l) => ({ line_no: l.line_no, pack_qty: 0 })),
        photos: [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const chosen = form.data.lines.some((l) => l.pack_qty > 0);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url, { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };

    if (!open) {
        return (
            <section className="space-y-1">
                <Button type="button" variant="outline" className="h-11 w-full" onClick={() => setOpen(true)}>
                    Report a problem
                </Button>
                <p className="text-xs text-muted-foreground">Faulty, damaged or not what you ordered? Tell us, at any time.</p>
            </section>
        );
    }

    return (
        <section className="space-y-3 rounded-md border p-4">
            <h2 className="font-semibold">Report a problem</h2>
            <form onSubmit={submit} className="space-y-3" noValidate>
                <div className="space-y-1">
                    <label htmlFor="problem-reason" className="block font-medium">
                        What is wrong?
                    </label>
                    <select id="problem-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className="h-10 w-full rounded-md border border-input bg-transparent px-2">
                        {problem.reasons.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="space-y-1">
                    <label htmlFor="problem-choice" className="block font-medium">After 30 days: choose repair or replacement</label>
                    <select id="problem-choice" value={form.data.customer_choice} onChange={(e) => form.setData('customer_choice', e.target.value)} className="h-10 w-full rounded-md border border-input bg-transparent px-2">
                        <option value="">Within 30 days: refund</option>
                        <option value="repair">Repair</option>
                        <option value="replacement">Replacement</option>
                    </select>
                    {errors.customer_choice && <p className="text-xs text-red-700">{errors.customer_choice}</p>}
                </div>
                {problem.lines.map((l) => (
                    <div key={l.line_no} className="flex items-start justify-between gap-3">
                        <label htmlFor={`problem-line-${l.line_no}`} className="min-w-0">
                            <span className="block font-medium leading-tight">{l.name}</span>
                            <span className="block text-xs text-muted-foreground">
                                <span className="font-mono">{l.sku_code}</span> · {l.pack_label}
                            </span>
                        </label>
                        <select
                            id={`problem-line-${l.line_no}`}
                            value={form.data.lines.find((x) => x.line_no === l.line_no)?.pack_qty ?? 0}
                            onChange={(e) => form.setData('lines', form.data.lines.map((x) => (x.line_no === l.line_no ? { ...x, pack_qty: Number(e.target.value) } : x)))}
                            className="h-10 shrink-0 rounded-md border border-input bg-transparent px-2"
                        >
                            {Array.from({ length: l.reportable_pack_qty + 1 }, (_, n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </select>
                    </div>
                ))}
                {errors.lines && <p className="text-xs text-red-700">{errors.lines}</p>}
                <div className="space-y-1">
                    <label htmlFor="problem-detail" className="block font-medium">
                        Tell us what happened
                    </label>
                    <textarea id="problem-detail" value={form.data.detail} onChange={(e) => form.setData('detail', e.target.value)} rows={3} maxLength={2000} className="w-full rounded-md border border-input bg-transparent p-2" />
                    {errors.detail && <p className="text-xs text-red-700">{errors.detail}</p>}
                </div>
                <div className="space-y-1">
                    <label htmlFor="problem-photos" className="block font-medium">
                        Photos (optional, up to 5)
                    </label>
                    <input id="problem-photos" type="file" multiple accept=".jpg,.jpeg,.png,.webp" onChange={(e) => form.setData('photos', Array.from(e.target.files ?? []).slice(0, 5))} className="block w-full text-xs" />
                </div>
                <Button type="submit" className="h-11 w-full" disabled={!chosen || form.data.detail.trim().length < 5 || form.processing}>
                    Send report
                </Button>
            </form>
        </section>
    );
}

/** A payment deadline in UK time (05.6 §7A.5). */
function formatDeadline(iso: string): string {
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/London', weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}
