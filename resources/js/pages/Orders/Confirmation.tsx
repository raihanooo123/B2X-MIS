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
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CheckCircle2 } from 'lucide-react';

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
        payment_status: string;
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
}

const BRANDS: Record<string, string> = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express', maestro: 'Maestro', discover: 'Discover', diners: 'Diners Club', jcb: 'JCB', unionpay: 'UnionPay' };

/** "Visa ending 4242" — never more of the card than that (07 §6.4). */
function cardDescription(card: NonNullable<ConfirmationProps['order']['card_payment']>): string {
    const brand = card.card_brand ? (BRANDS[card.card_brand] ?? card.card_brand) : 'Card';

    return card.card_last4 ? `${brand} ending ${card.card_last4}` : brand;
}

/** What the buyer should expect, by how they chose to pay. */
function nextSteps(order: ConfirmationProps['order']): string[] {
    const common = 'We will email you when your order is dispatched.';

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

export default function Confirmation({ display_mode: mode, order, guest }: ConfirmationProps) {
    const { display_timezone: timeZone, auth } = usePage<SharedProps>().props;

    const address = order.delivery_address;

    return (
        <>
            <Head title={`Order ${order.order_number}`} />
            <div className="mx-auto max-w-[900px] px-4 py-4">
                <header className="mb-6 flex flex-wrap items-center justify-end gap-x-4">
                    <AccountMenu />
                </header>

                <section className="mb-8 flex items-start gap-3">
                    <CheckCircle2 className="mt-0.5 size-8 shrink-0 text-emerald-600" aria-hidden />
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">{guest ? `Your order ${order.order_number}` : 'Thank you — your order is placed'}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Order number <strong className="font-mono text-foreground">{order.order_number}</strong>
                            {order.placed_at && <> · {formatDateTime(order.placed_at, timeZone)}</>}
                            {order.customer_reference && <> · Your reference {order.customer_reference}</>}
                        </p>
                        {order.payment_status === 'paid' && (
                            <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800">
                                <CheckCircle2 className="size-3.5" aria-hidden /> Paid{order.card_payment && ` · ${cardDescription(order.card_payment)}`}
                            </p>
                        )}
                    </div>
                </section>

                <div className="grid gap-6 md:grid-cols-[1fr_280px]">
                    <section aria-label="Order lines" className="min-w-0">
                        <div className="overflow-x-auto rounded-md border">
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
                            <h2 className="mb-2 font-semibold">What happens next</h2>
                            <ol className="list-decimal space-y-1.5 pl-5 text-muted-foreground">
                                {nextSteps(order).map((step) => (
                                    <li key={step}>{step}</li>
                                ))}
                            </ol>
                        </section>

                        {guest?.status && (
                            <p role="status" className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-emerald-900">
                                {guest.status}
                            </p>
                        )}

                        {guest?.can_save_details && !guest.status && <SaveDetails guest={guest} />}

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
