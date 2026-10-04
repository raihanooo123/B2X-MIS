import { Link } from '@inertiajs/react';
import { Download, Receipt } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import type { ShellProps } from '@/components/storefront/StorefrontLayout';
import { formatMinor } from '@/lib/money';

interface ReceiptSummary { id: string; receipt_number: string; issued_on: string; status: string; total_gross_minor: number; order_number: string; order_url: string; download_url: string | null }
export default function Receipts({ history, shell }: { history: { items: ReceiptSummary[]; next: string | null }; shell: ShellProps }) {
    return <AccountLayout title="My receipts" shell={shell} description="Receipts for your orders, newest first.">
        <AccountCard>
            {history.items.length === 0 ? <p className="px-6 py-12 text-center text-muted-foreground">No receipts have been issued yet. <Link href="/account/orders" className="underline">My orders</Link></p> : <ul className="divide-y">
                {history.items.map((receipt) => <li key={receipt.id} className="flex flex-wrap items-center justify-between gap-5 p-6">
                    <div><p className="flex items-center gap-2 text-lg font-semibold"><Receipt className="size-5 text-primary" aria-hidden />{receipt.receipt_number}</p><p className="text-sm text-muted-foreground">{receipt.issued_on} · {receipt.status.replace(/_/g, ' ')}</p><Link href={receipt.order_url} className="inline-flex min-h-11 items-center text-sm underline md:min-h-0">Order {receipt.order_number}</Link></div>
                    <div className="space-y-3 sm:text-right"><p className="font-semibold">{formatMinor(receipt.total_gross_minor)}</p>{receipt.download_url ? <Button asChild variant="outline" className="min-h-11 md:min-h-9"><a href={receipt.download_url}><Download className="size-4" aria-hidden />Download PDF</a></Button> : <p className="text-sm text-muted-foreground">Receipt PDF not ready</p>}</div>
                </li>)}
            </ul>}
        </AccountCard>
        {history.next && <Link href={`/account/receipts?cursor=${encodeURIComponent(history.next)}`} className="mt-5 inline-block rounded-md border bg-background px-4 py-3">More receipts</Link>}
    </AccountLayout>;
}
