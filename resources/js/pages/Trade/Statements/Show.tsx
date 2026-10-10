/**
 * 05.17 §4 — one statement, exactly as it was fixed when requested: debt
 * (opening, invoices, cash, credit notes, closing, ageing) and spendable
 * balance (opening, movements, closing) shown apart, plus on-account
 * orders not yet invoiced. Figures come from the stored statement, never
 * recalculated, so later postings do not change it.
 */
import { DocumentPanel } from '@/components/trade/DocumentPanel';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState } from '@/components/trade/states';
import { SummaryCard } from '@/components/trade/SummaryCard';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import type { DocumentState } from '@/lib/api/documents';
import { formatUkDate, formatUkDateTime } from '@/lib/dateTime';
import { dayIso, type StatementRow } from '@/lib/trade/selfService';

interface Movement {
    date_display: string | null;
    amount_minor: number;
    number?: string;
    invoice_number?: string;
    credit_note_number?: string;
    type_label?: string;
    reference?: string | null;
}

interface Figures {
    debt: {
        opening_minor: number;
        invoiced_minor: number;
        cash_minor: number;
        credits_minor: number;
        closing_minor: number;
        invoices: Movement[];
        cash_allocations: Movement[];
        credit_allocations: Movement[];
        ageing: { bucket: string; label: string; count: number; amount_minor: number }[];
    };
    balance: { opening_minor: number; movements_total_minor: number; closing_minor: number; movements: Movement[] };
    uninvoiced_holds_minor: number;
}

interface StatementDetail extends StatementRow {
    figures: Figures | null;
    document: DocumentState;
}

function Rows({ title, rows, describe }: { title: string; rows: Movement[]; describe: (m: Movement) => string }) {
    return (
        <div>
            <h3 className="mb-2 text-sm font-semibold">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">None in this period.</p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {rows.map((m, i) => (
                        <li key={i} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                            <span>
                                <span className="tabular-nums text-muted-foreground">{m.date_display}</span> · {describe(m)}
                            </span>
                            <Money minor={m.amount_minor} />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function StatementShow({ statement }: { statement: StatementDetail }) {
    const tz = useDisplayTimezone();
    const period = `${formatUkDate(dayIso(statement.from_on), tz)} – ${formatUkDate(dayIso(statement.to_on), tz)}`;
    const f = statement.figures;

    return (
        <TradeShell title="Statement">
            <PageHeader
                breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: 'Statements', href: '/trade/statements' }, { label: period }]}
                title={`Statement ${period}`}
                description={<p>Figures as at {formatUkDateTime(statement.cutoff_at, tz, true)}{statement.requested_by && <> · requested by {statement.requested_by}</>}</p>}
            />

            {f === null ? (
                <EmptyState title="This statement has no figures">Contact accounts for a copy.</EmptyState>
            ) : (
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="flex min-w-0 flex-col gap-6">
                        <section aria-labelledby="debt-heading" className="flex flex-col gap-4 rounded-xl border bg-background p-5">
                            <h2 id="debt-heading" className="text-lg font-semibold">
                                What you owe
                            </h2>
                            <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <SummaryCard label="Owed at the start" value={<Money minor={f.debt.opening_minor} />} />
                                <SummaryCard label="Invoiced" value={<Money minor={f.debt.invoiced_minor} />} />
                                <SummaryCard label="Paid" value={<Money minor={f.debt.cash_minor} />} />
                                <SummaryCard label="Credited" value={<Money minor={f.debt.credits_minor} />} />
                                <SummaryCard label="Owed at the end" value={<Money minor={f.debt.closing_minor} />} emphasis />
                            </dl>
                            <Rows title="Invoices" rows={f.debt.invoices} describe={(m) => `Invoice ${m.number ?? ''}`} />
                            <Rows title="Payments" rows={f.debt.cash_allocations} describe={(m) => `Payment against ${m.invoice_number ?? ''}`} />
                            <Rows title="Credit notes" rows={f.debt.credit_allocations} describe={(m) => `${m.credit_note_number ?? ''} against ${m.invoice_number ?? ''}`} />
                            <div>
                                <h3 className="mb-2 text-sm font-semibold">Owed at the end, by age</h3>
                                <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                                    {f.debt.ageing.map((b) => (
                                        <li key={b.bucket} className="rounded-lg border p-3">
                                            <p className="text-xs text-muted-foreground">{b.label}</p>
                                            <p className="font-semibold"><Money minor={b.amount_minor} /></p>
                                            <p className="text-xs tabular-nums text-muted-foreground">{b.count} invoice{b.count === 1 ? '' : 's'}</p>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </section>

                        <section aria-labelledby="balance-heading" className="flex flex-col gap-4 rounded-xl border bg-background p-5">
                            <h2 id="balance-heading" className="text-lg font-semibold">
                                Account balance (your money to spend)
                            </h2>
                            <p className="text-sm text-muted-foreground">Kept separate from what you owe. A credit note set against an invoice is counted under &ldquo;Credited&rdquo; above, not again here.</p>
                            <dl className="grid gap-3 sm:grid-cols-3">
                                <SummaryCard label="At the start" value={<Money minor={f.balance.opening_minor} />} />
                                <SummaryCard label="Movements" value={<Money minor={f.balance.movements_total_minor} />} />
                                <SummaryCard label="At the end" value={<Money minor={f.balance.closing_minor} />} emphasis />
                            </dl>
                            <Rows title="Movements" rows={f.balance.movements} describe={(m) => `${m.type_label ?? ''}${m.reference ? ` · ${m.reference}` : ''}`} />
                        </section>
                    </div>

                    <aside className="flex flex-col gap-6">
                        <DocumentPanel type="statement" source={statement.id} initial={statement.document} label="statement" />
                        <dl>
                            <SummaryCard label="On-account orders not yet invoiced" value={<Money minor={f.uninvoiced_holds_minor} />} note="As at the time of the statement" />
                        </dl>
                    </aside>
                </div>
            )}
        </TradeShell>
    );
}
