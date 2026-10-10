/**
 * 05.17 §4 — one credit note: the original invoice, net, VAT and total,
 * how much was set against that invoice's debt and how much became
 * account balance, and the archived PDF.
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
import type { CreditNoteRow } from '@/lib/trade/selfService';

interface CreditNoteDetail extends CreditNoteRow {
    net_minor: number;
    tax_minor: number;
    original_invoice: { id: string; number: string; issued_at: string } | null;
    order: { id: string; number: string } | null;
    allocations: { at: string; amount_minor: number; invoice: { id: string; number: string } }[];
    document: DocumentState;
}

export default function CreditNoteShow({ credit_note: note }: { credit_note: CreditNoteDetail }) {
    const tz = useDisplayTimezone();

    return (
        <TradeShell title={`Credit note ${note.number}`}>
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Credit notes', href: '/trade/credit-notes' }, { label: note.number }]}
                title={`Credit note ${note.number}`}
                status={<StatusBadge tone={note.status === 'void' ? 'neutral' : 'success'}>{note.status === 'void' ? 'Void' : 'Issued'}</StatusBadge>}
                description={
                    <p>
                        Issued {formatUkDate(note.issued_at, tz)} · {note.reason_label}
                        {note.order && (
                            <>
                                {' '}· Order{' '}
                                <Link href={`/trade/orders/${note.order.id}`} className="underline underline-offset-4">
                                    {note.order.number}
                                </Link>
                            </>
                        )}
                    </p>
                }
            />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    <section aria-labelledby="original-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="original-heading" className="mb-2 text-lg font-semibold">
                            Original invoice
                        </h2>
                        {note.original_invoice ? (
                            <p className="text-sm">
                                <Link href={`/trade/invoices/${note.original_invoice.id}`} className="font-medium underline underline-offset-4">
                                    {note.original_invoice.number}
                                </Link>{' '}
                                <span className="text-muted-foreground">issued {formatUkDate(note.original_invoice.issued_at, tz)}</span>
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">Not linked to a single invoice.</p>
                        )}
                    </section>

                    <section aria-labelledby="allocations-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="allocations-heading" className="mb-3 text-lg font-semibold">
                            Where the credit went
                        </h2>
                        {note.allocations.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Not set against any invoice. The whole amount is account balance.</p>
                        ) : (
                            <ol className="flex flex-col gap-2 text-sm">
                                {note.allocations.map((a, i) => (
                                    <li key={i} className="flex flex-wrap justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2">
                                        <span>
                                            <span className="tabular-nums">{formatUkDate(a.at, tz)}</span> · Set against{' '}
                                            <Link href={`/trade/invoices/${a.invoice.id}`} className="underline underline-offset-4">
                                                {a.invoice.number}
                                            </Link>
                                        </span>
                                        <Money minor={a.amount_minor} className="font-medium" />
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                </div>

                <aside className="flex flex-col gap-6">
                    <DocumentPanel type="credit_note" source={note.id} initial={note.document} label="credit note" />
                    <section aria-labelledby="totals-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="totals-heading" className="mb-2 text-base font-semibold">
                            Amounts
                        </h2>
                        <dl className="divide-y text-sm">
                            <div className="flex justify-between gap-4 py-1.5"><dt className="text-muted-foreground">Net</dt><dd><Money minor={note.net_minor} /></dd></div>
                            <div className="flex justify-between gap-4 py-1.5"><dt className="text-muted-foreground">VAT</dt><dd><Money minor={note.tax_minor} /></dd></div>
                            <div className="flex justify-between gap-4 py-1.5 font-semibold"><dt>Total</dt><dd><Money minor={note.total_gross_minor} /></dd></div>
                            <div className="flex justify-between gap-4 py-1.5"><dt className="text-muted-foreground">Set against debt</dt><dd><Money minor={note.allocated_minor} /></dd></div>
                            <div className="flex justify-between gap-4 py-1.5"><dt className="text-muted-foreground">To account balance</dt><dd><Money minor={note.to_balance_minor} /></dd></div>
                        </dl>
                    </section>
                </aside>
            </div>
        </TradeShell>
    );
}
