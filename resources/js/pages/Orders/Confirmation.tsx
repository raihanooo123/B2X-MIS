/**
 * Order confirmation: the order number, its lines and totals, where it is
 * going, and what happens next. Every figure is the order's own snapshot
 * (CLAUDE.md invariant 4) — never re-priced — shown ex- or inc-VAT per
 * `display_mode`.
 */
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { PaymentMethod } from '@/lib/api/checkout';
import { lineTotalMinor, packPrice, totalsRows, vatLabel, type DisplayMode } from '@/lib/cart/display';
import { formatMinor } from '@/lib/money';
import { cn } from '@/lib/utils';

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
        subtotal_net_minor: number;
        spend_break_discount_minor: number;
        shipping_net_minor: number;
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
}

function placedAt(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleString('en-GB', { dateStyle: 'long', timeStyle: 'short' });
}

/** What the buyer should expect, by how they chose to pay. */
function nextSteps(order: ConfirmationProps['order']): string[] {
    const common = 'We will email you when your order is dispatched.';

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

export default function Confirmation({ display_mode: mode, order }: ConfirmationProps) {
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
                        <h1 className="text-xl font-semibold tracking-tight">Thank you — your order is placed</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Order number <strong className="font-mono text-foreground">{order.order_number}</strong>
                            {order.placed_at && <> · {placedAt(order.placed_at)}</>}
                            {order.customer_reference && <> · Your reference {order.customer_reference}</>}
                        </p>
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
                            {totalsRows(order, mode).map((row) => (
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
                                <Link href="/order-pad">Continue shopping</Link>
                            </Button>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}
