/**
 * 05.17 §4 — one invoice: its fixed issued identity (seller, customer and
 * delivery as at issue), lines and VAT exactly as printed, and how it has
 * been paid or credited since. Download is the primary action once the
 * archived PDF is ready; preparing and failure states stay on this page.
 */
import { Link } from '@inertiajs/react';

import { DocumentPanel } from '@/components/trade/DocumentPanel';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import type { DocumentState } from '@/lib/api/documents';
import { formatUkDate } from '@/lib/dateTime';
import { invoiceBadge, type InvoiceRow } from '@/lib/trade/selfService';

interface PrintedLine {
    line_no: number;
    sku_code: string;
    description: string;
    pack_label: string;
    pack_qty: number | null;
    base_qty: number;
    unit_price_net_e4: number;
    vat_rate: string;
    line_net_minor: number;
    line_vat_minor: number;
}

interface Party {
    name?: string | null;
    legal_name?: string | null;
    account_code?: string | null;
    vat_number?: string | null;
    company_number?: string | null;
    address_lines: string[];
}

interface InvoiceDetail extends InvoiceRow {
    kind: 'receipt' | 'vat_invoice';
    payment_terms: string | null;
    order: { id: string; number: string } | null;
    issued_identity: { seller: Party | null; customer: Party | null; delivery_address_lines: string[] } | null;
    lines: PrintedLine[];
    carriage: { description: string; net_minor: number; vat_rate: string; vat_minor: number } | null;
    vat_summary: { rate: string; net_minor: number; vat_minor: number }[];
    totals: { subtotal_net_minor: number; discount_net_minor: number; shipping_net_minor: number; tax_minor: number; total_gross_minor: number };
    allocations: { kind: 'payment' | 'credit_note'; at: string; amount_minor: number; credit_note: { id: string; number: string } | null }[];
    document: DocumentState;
}

function PartyBlock({ title, party }: { title: string; party: Party | null }) {
    if (party === null) {
        return null;
    }

    return (
        <div>
            <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
            <address className="text-sm not-italic leading-relaxed">
                <span className="block font-medium">{party.legal_name ?? party.name}</span>
                {party.address_lines.map((l) => (
                    <span key={l} className="block">
                        {l}
                    </span>
                ))}
                {party.account_code && <span className="block text-muted-foreground">Account {party.account_code}</span>}
                {party.vat_number && <span className="block text-muted-foreground">VAT {party.vat_number}</span>}
            </address>
        </div>
    );
}

export default function InvoiceShow({ invoice }: { invoice: InvoiceDetail }) {
    const tz = useDisplayTimezone();
    const badge = invoiceBadge(invoice);
    const title = invoice.kind === 'receipt' ? 'Receipt' : 'Invoice';

    return (
        <TradeShell title={`${title} ${invoice.number}`}>
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Invoices', href: '/trade/invoices' }, { label: invoice.number }]}
                title={`${title} ${invoice.number}`}
                status={<StatusBadge tone={badge.tone}>{badge.label}</StatusBadge>}
                description={
                    <p>
                        Issued {formatUkDate(invoice.issued_at, tz)}
                        {invoice.due_at && <> · Due {formatUkDate(invoice.due_at, tz)}</>}
                        {invoice.order && (
                            <>
                                {' '}· Order{' '}
                                <Link href={`/trade/orders/${invoice.order.id}`} className="underline underline-offset-4">
                                    {invoice.order.number}
                                </Link>
                            </>
                        )}
                    </p>
                }
            />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <section aria-labelledby="identity-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="identity-heading" className="mb-3 text-lg font-semibold">
                            As issued
                        </h2>
                        {invoice.issued_identity === null ? (
                            <p className="text-sm text-muted-foreground">This invoice was issued before documents were archived. Its lines and totals are below; contact accounts for a copy of the original.</p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-3">
                                <PartyBlock title="From" party={invoice.issued_identity.seller} />
                                <PartyBlock title="To" party={invoice.issued_identity.customer} />
                                {invoice.issued_identity.delivery_address_lines.length > 0 && <PartyBlock title="Delivered to" party={{ address_lines: invoice.issued_identity.delivery_address_lines }} />}
                            </div>
                        )}
                    </section>

                    <section aria-labelledby="lines-heading" className="overflow-hidden rounded-xl border bg-background">
                        <h2 id="lines-heading" className="px-5 pt-5 text-lg font-semibold">
                            Items
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="mt-3 w-full text-sm">
                                <caption className="sr-only">Items on {invoice.number}</caption>
                                <thead className="border-y bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">Item</th>
                                        <th scope="col" className="px-3 py-3">Quantity</th>
                                        <th scope="col" className="px-3 py-3 text-right">Unit net</th>
                                        <th scope="col" className="px-3 py-3 text-right">VAT</th>
                                        <th scope="col" className="px-5 py-3 text-right">Net</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {invoice.lines.map((l) => (
                                        <tr key={l.line_no}>
                                            <td className="px-5 py-3">
                                                <span className="block font-medium">{l.description}</span>
                                                <span className="font-mono text-xs text-muted-foreground">{l.sku_code}</span>
                                            </td>
                                            <td className="px-3 py-3 tabular-nums">
                                                {l.pack_qty === null ? '' : `${l.pack_qty} × ${l.pack_label}`}
                                                <span className="block text-xs text-muted-foreground">{l.base_qty.toLocaleString('en-GB')} units</span>
                                            </td>
                                            <td className="px-3 py-3 text-right"><Money e4={l.unit_price_net_e4} /></td>
                                            <td className="px-3 py-3 text-right tabular-nums">{l.vat_rate}</td>
                                            <td className="px-5 py-3 text-right"><Money minor={l.line_net_minor} /></td>
                                        </tr>
                                    ))}
                                    {invoice.carriage && (
                                        <tr>
                                            <td className="px-5 py-3 font-medium" colSpan={3}>{invoice.carriage.description}</td>
                                            <td className="px-3 py-3 text-right tabular-nums">{invoice.carriage.vat_rate}</td>
                                            <td className="px-5 py-3 text-right"><Money minor={invoice.carriage.net_minor} /></td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section aria-labelledby="allocations-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="allocations-heading" className="mb-3 text-lg font-semibold">
                            Payments and credits
                        </h2>
                        {invoice.allocations.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Nothing paid or credited yet.</p>
                        ) : (
                            <ol className="flex flex-col gap-2 text-sm">
                                {[...invoice.allocations].sort((a, b) => a.at.localeCompare(b.at)).map((a, i) => (
                                    <li key={i} className="flex flex-wrap justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2">
                                        <span>
                                            <span className="tabular-nums">{formatUkDate(a.at, tz)}</span> ·{' '}
                                            {a.kind === 'payment' ? (
                                                'Payment received'
                                            ) : (
                                                <>
                                                    Credit note{' '}
                                                    <Link href={`/trade/credit-notes/${a.credit_note?.id}`} className="underline underline-offset-4">
                                                        {a.credit_note?.number}
                                                    </Link>
                                                </>
                                            )}
                                        </span>
                                        <Money minor={a.amount_minor} className="font-medium" />
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                </div>

                <aside className="flex flex-col gap-6">
                    <DocumentPanel type="invoice" source={invoice.id} initial={invoice.document} label={title.toLowerCase()} />

                    <section aria-labelledby="totals-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="totals-heading" className="mb-2 text-base font-semibold">
                            Totals
                        </h2>
                        <dl className="divide-y text-sm">
                            {invoice.vat_summary.map((v) => (
                                <div key={v.rate} className="flex justify-between gap-4 py-1.5">
                                    <dt className="text-muted-foreground">VAT at {v.rate} on <Money minor={v.net_minor} /></dt>
                                    <dd><Money minor={v.vat_minor} /></dd>
                                </div>
                            ))}
                            <div className="flex justify-between gap-4 py-1.5 font-semibold">
                                <dt>Total</dt>
                                <dd><Money minor={invoice.totals.total_gross_minor} /></dd>
                            </div>
                            <div className="flex justify-between gap-4 py-1.5">
                                <dt className="text-muted-foreground">Paid</dt>
                                <dd><Money minor={invoice.paid_minor} /></dd>
                            </div>
                            <div className="flex justify-between gap-4 py-1.5">
                                <dt className="text-muted-foreground">Credited</dt>
                                <dd><Money minor={invoice.credited_minor} /></dd>
                            </div>
                            <div className="flex justify-between gap-4 py-1.5 font-semibold">
                                <dt>Outstanding</dt>
                                <dd><Money minor={invoice.outstanding_minor} /></dd>
                            </div>
                        </dl>
                    </section>
                </aside>
            </div>
        </TradeShell>
    );
}
