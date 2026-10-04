import { Link } from '@inertiajs/react';
import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import type { ShellProps } from '@/components/storefront/StorefrontLayout';
import { formatMinor } from '@/lib/money';

interface ReceiptSummary { id: string; receipt_number: string; issued_on: string; status: string; total_gross_minor: number; order_number: string; order_url: string; download_url: string | null }
export default function Receipts({ history, shell }: { history: { items: ReceiptSummary[]; next: string | null }; shell: ShellProps }) {
    return <AccountLayout title="My receipts" shell={shell} description="Receipts for your orders, newest first.">
        <AccountCard>
            {history.items.length === 0 ? <p className="p-5">No receipts have been issued yet. <Link href="/account/orders" className="underline">My orders</Link></p> : <ul className="divide-y">
                {history.items.map((receipt) => <li key={receipt.id} className="flex flex-wrap items-center justify-between gap-4 p-5">
                    <div><p className="font-semibold">{receipt.receipt_number}</p><p className="text-sm text-muted-foreground">{receipt.issued_on} · {receipt.status.replace(/_/g, ' ')}</p><Link href={receipt.order_url} className="text-sm underline">Order {receipt.order_number}</Link></div>
                    <div className="text-right"><p className="font-semibold">{formatMinor(receipt.total_gross_minor)}</p>{receipt.download_url ? <a href={receipt.download_url} className="text-sm underline">Download receipt PDF</a> : <p className="text-sm text-muted-foreground">Receipt PDF not ready</p>}</div>
                </li>)}
            </ul>}
        </AccountCard>
        {history.next && <Link href={`/account/receipts?cursor=${encodeURIComponent(history.next)}`} className="mt-5 inline-block rounded-md border bg-background px-4 py-3">More receipts</Link>}
    </AccountLayout>;
}
