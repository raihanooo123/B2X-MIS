import { Link } from '@inertiajs/react';
import { ArrowUpRight, CheckCircle2, Clock3, Package, ShoppingBag, Truck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import type { ShellProps } from '@/components/storefront/StorefrontLayout';
import { formatMinor } from '@/lib/money';
import { cn } from '@/lib/utils';

interface OrderSummary { id: string; order_number: string; placed_on: string; status: string; payment_status: string; total_gross_minor: number; url: string }
export default function Orders({ history, shell }: { history: { items: OrderSummary[]; next: string | null }; shell: ShellProps }) {
    return <AccountLayout title="My orders" shell={shell} description="All your orders, newest first.">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary/15 bg-primary/5 px-5 py-4">
            <div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-xl bg-background text-primary"><Package className="size-5" aria-hidden /></div><div><p className="text-sm font-semibold">Your order history</p><p className="text-xs text-muted-foreground">View details, payments and available order actions.</p></div></div>
            <Link href="/" className="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-primary hover:underline">Continue shopping <ArrowUpRight className="size-4" aria-hidden /></Link>
        </div>
        <AccountCard className="border-0 bg-transparent shadow-none">
            {history.items.length === 0 ? <div className="flex flex-col items-center px-6 py-14 text-center"><ShoppingBag className="mb-4 size-10 text-muted-foreground" aria-hidden /><h2 className="text-lg font-semibold">Your first order starts here</h2><p className="mb-6 mt-2 text-sm text-muted-foreground">Once you place an order, you can follow it here.</p><Button asChild className="min-h-11 md:min-h-9"><Link href="/">Continue shopping</Link></Button></div> : <ul className="space-y-5">
                {history.items.map((order) => <li key={order.id} className="overflow-hidden rounded-2xl border bg-background shadow-sm transition-shadow hover:shadow-md">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/30 px-5 py-4 sm:px-6">
                        <div className="flex items-center gap-3"><div className="flex size-11 items-center justify-center rounded-xl border bg-background text-primary"><ShoppingBag className="size-5" aria-hidden /></div><div><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Order number</p><Link href={order.url} className="inline-flex min-h-11 items-center text-lg font-semibold hover:text-primary md:min-h-0">{order.order_number}</Link></div></div>
                        <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold capitalize', order.status === 'cancelled' ? 'border-border bg-muted text-foreground/80' : 'border-primary/20 bg-primary/5 text-primary')}>
                            {order.status === 'dispatched' ? <Truck className="size-3.5" aria-hidden /> : order.status === 'confirmed' ? <CheckCircle2 className="size-3.5" aria-hidden /> : <Clock3 className="size-3.5" aria-hidden />}
                            {order.status.replace(/_/g, ' ')}
                        </span>
                    </div>
                    <div className="flex flex-wrap items-end justify-between gap-5 p-5 sm:p-6">
                        <dl className="flex flex-wrap gap-x-10 gap-y-4 text-sm"><div><dt className="text-muted-foreground">Placed on</dt><dd className="mt-1 font-medium">{order.placed_on}</dd></div><div><dt className="text-muted-foreground">Payment</dt><dd className="mt-1 font-medium capitalize">{order.payment_status.replace(/_/g, ' ')}</dd></div><div><dt className="text-muted-foreground">Order total</dt><dd className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{formatMinor(order.total_gross_minor)}</dd></div></dl>
                        <Button asChild className="min-h-11 md:min-h-9 w-full rounded-xl sm:w-auto"><Link href={order.url}>View order <ArrowUpRight className="size-4" aria-hidden /></Link></Button>
                    </div>
                </li>)}
            </ul>}
        </AccountCard>
        {history.next && <Link href={`/account/orders?cursor=${encodeURIComponent(history.next)}`} className="mt-5 inline-block rounded-md border bg-background px-4 py-3">More orders</Link>}
        {history.next && <Link href="/account/orders" className="ml-4 underline">Newest orders</Link>}
    </AccountLayout>;
}
