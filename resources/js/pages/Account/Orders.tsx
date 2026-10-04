import { Link } from '@inertiajs/react';
import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import type { ShellProps } from '@/components/storefront/StorefrontLayout';
import { formatMinor } from '@/lib/money';

interface OrderSummary { id: string; order_number: string; placed_on: string; status: string; payment_status: string; total_gross_minor: number; url: string }
export default function Orders({ history, shell }: { history: { items: OrderSummary[]; next: string | null }; shell: ShellProps }) {
    return <AccountLayout title="My orders" shell={shell} description="All your orders, newest first.">
        <AccountCard>
            {history.items.length === 0 ? <p className="p-5">You have no orders yet. <Link href="/" className="underline">Continue shopping</Link></p> : <ul className="divide-y">
                {history.items.map((order) => <li key={order.id} className="flex flex-wrap items-center justify-between gap-4 p-5">
                    <div><Link href={order.url} className="font-semibold underline">{order.order_number}</Link><p className="text-sm text-muted-foreground">{order.placed_on}</p><p className="text-sm">{order.status.replace(/_/g, ' ')} · {order.payment_status.replace(/_/g, ' ')}</p></div>
                    <div className="text-right"><p className="font-semibold">{formatMinor(order.total_gross_minor)}</p><Link href={order.url} className="text-sm underline">Open order</Link></div>
                </li>)}
            </ul>}
        </AccountCard>
        {history.next && <Link href={`/account/orders?cursor=${encodeURIComponent(history.next)}`} className="mt-5 inline-block rounded-md border bg-background px-4 py-3">More orders</Link>}
        {history.next && <Link href="/account/orders" className="ml-4 underline">Newest orders</Link>}
    </AccountLayout>;
}
