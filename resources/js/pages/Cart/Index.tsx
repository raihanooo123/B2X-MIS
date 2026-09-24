/**
 * The cart (06 §8 `/cart`): Baymard UX Benchmark Edition
 * Features clear price transparency, security trust signals, inline item validation,
 * and high-density line editing designed for wholesale efficiency.
 */
import { Head, Link, router } from '@inertiajs/react';
import { 
    AlertCircle, 
    ArrowLeft, 
    Check, 
    HelpCircle, 
    ImageOff, 
    Lock, 
    Minus, 
    Package, 
    Plus, 
    ShieldCheck, 
    ShoppingCart, 
    Trash2, 
    Truck 
} from 'lucide-react';
import { useEffect, useMemo, useState, type KeyboardEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCheckoutPreview, type CheckoutPreview, type PreviewBlocker, type PreviewLine } from '@/lib/api/checkout';
import { useCart, useRemoveCartLine, useUpdateCartLine, type CartLine } from '@/lib/api/orderPad';
import { lineTotalMinor, packPrice, totalsRows, vatLabel, type DisplayMode } from '@/lib/cart/display';
import { formatMinor } from '@/lib/money';
import { DESKTOP_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { cn } from '@/lib/utils';

const MAX_PACK_QTY = 1_000_000;
const IDENTITY_BLOCKERS = new Set(['sign_in_required', 'application_pending', 'email_unverified', 'not_permitted_to_order']);

interface CartPageProps {
    display_mode: DisplayMode;
    estimate_country: { code: string; name: string };
}

export default function CartIndex({ display_mode: mode, estimate_country: country }: CartPageProps) {
    const cart = useCart();
    const lines = cart.data?.lines ?? [];
    const preview = useCheckoutPreview(country.code, { enabled: lines.length > 0 });
    const desktop = useMediaQuery(DESKTOP_QUERY);

    const previewByLine = useMemo(() => new Map((preview.data?.lines ?? []).map((l) => [l.cart_line_id, l])), [preview.data]);
    const blockersByLine = useMemo(() => lineBlockers(preview.data?.blockers ?? []), [preview.data]);
    const orderBlockers = (preview.data?.blockers ?? []).filter((b) => !IDENTITY_BLOCKERS.has(b.code) && lineIdsOf(b).length === 0 && b.code !== 'cart_empty');

    return (
        <div className="min-h-screen bg-slate-100/70 pb-20 font-sans text-slate-900 antialiased">
            <Head title="Shopping Cart — Order Review" />

            {/* Baymard Benchmark Header with Order Progress Indicator */}
            <header className="border-b border-slate-200 bg-white shadow-2xs">
                <div className="mx-auto flex max-w-[1240px] items-center justify-between px-4 py-3.5 sm:px-6">
                    <Link 
                        href="/order-pad" 
                        className="inline-flex items-center gap-2 text-xs font-semibold text-slate-600 hover:text-slate-900 transition-colors"
                    >
                        <ArrowLeft className="size-4" aria-hidden /> Return to Order Pad
                    </Link>

                    {/* Step Progress Bar */}
                    <ol className="hidden md:flex items-center gap-3 text-xs font-medium text-slate-400">
                        <li className="flex items-center gap-1.5 text-slate-900 font-bold">
                            <span className="flex size-5 items-center justify-center rounded-full bg-slate-900 text-[10px] text-white">1</span>
                            Shopping Cart
                        </li>
                        <span className="h-0.5 w-6 bg-slate-200" />
                        <li className="flex items-center gap-1.5">
                            <span className="flex size-5 items-center justify-center rounded-full bg-slate-200 text-[10px] text-slate-600">2</span>
                            Shipping & Delivery
                        </li>
                        <span className="h-0.5 w-6 bg-slate-200" />
                        <li className="flex items-center gap-1.5">
                            <span className="flex size-5 items-center justify-center rounded-full bg-slate-200 text-[10px] text-slate-600">3</span>
                            Payment & Confirm
                        </li>
                    </ol>

                    <AccountMenu />
                </div>
            </header>

            <main className="mx-auto max-w-[1240px] px-4 pt-8 sm:px-6">
                <div className="mb-6 flex flex-wrap items-baseline justify-between gap-4 border-b border-slate-200 pb-4">
                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Your Cart Overview</h1>
                        <p className="mt-1 text-xs text-slate-500">Review your selected items and quantities before moving to secure checkout.</p>
                    </div>
                    {lines.length > 0 && (
                        <div className="text-right text-xs text-slate-500">
                            Total items: <span className="font-bold text-slate-900">{lines.reduce((acc, l) => acc + l.pack_qty, 0)} packs</span> ({lines.length} unique SKUs)
                        </div>
                    )}
                </div>

                {cart.isPending ? (
                    <div className="space-y-3">
                        {Array.from({ length: 4 }, (_, i) => (
                            <Skeleton key={i} className="h-16 w-full rounded-lg bg-slate-200" />
                        ))}
                    </div>
                ) : cart.isError ? (
                    <div role="alert" className="rounded-lg border border-red-300 bg-red-50 p-4 text-xs text-red-900 shadow-2xs">
                        <div className="flex items-center gap-2 font-bold">
                            <AlertCircle className="size-4 text-red-600" />
                            <span>Cart Synchronization Failed</span>
                        </div>
                        <p className="mt-1 text-red-700">We couldn't retrieve your current cart. Please try re-synchronizing.</p>
                        <Button variant="outline" size="sm" className="mt-3 border-red-300 bg-white hover:bg-red-50 text-red-900" onClick={() => void cart.refetch()}>
                            Retry Loading
                        </Button>
                    </div>
                ) : lines.length === 0 ? (
                    <EmptyCart />
                ) : (
                    <div className="grid gap-8 lg:grid-cols-[1fr_380px] lg:items-start">
                        {/* Cart Items List Area */}
                        <section aria-label="Cart items" className="space-y-4">
                            {desktop ? (
                                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xs">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="border-b border-slate-200 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600 hover:bg-slate-50">
                                                <TableHead className="h-10 w-10 text-center">#</TableHead>
                                                <TableHead className="h-10 w-14">Item</TableHead>
                                                <TableHead className="h-10">Product Description</TableHead>
                                                <TableHead className="h-10">Packaging</TableHead>
                                                <TableHead className="h-10 text-right">Pack Price ({vatLabel(mode)})</TableHead>
                                                <TableHead className="h-10 text-center">Pack Qty</TableHead>
                                                <TableHead className="h-10 text-right">Subtotal</TableHead>
                                                <TableHead className="h-10 w-10"><span className="sr-only">Remove</span></TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="divide-y divide-slate-200">
                                            {lines.map((line, i) => (
                                                <CartRow
                                                    key={line.id}
                                                    rowNumber={i + 1}
                                                    line={line}
                                                    priced={previewByLine.get(line.id)}
                                                    pricing={preview.isPending}
                                                    blockers={blockersByLine.get(line.id) ?? []}
                                                    mode={mode}
                                                />
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            ) : (
                                <ol className="space-y-3">
                                    {lines.map((line, i) => (
                                        <CartCard
                                            key={line.id}
                                            rowNumber={i + 1}
                                            line={line}
                                            priced={previewByLine.get(line.id)}
                                            pricing={preview.isPending}
                                            blockers={blockersByLine.get(line.id) ?? []}
                                            mode={mode}
                                        />
                                    ))}
                                </ol>
                            )}

                            {/* Trust Signals Under Items */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 text-xs text-slate-600">
                                <div className="flex items-center gap-2.5 rounded-lg border border-slate-200 bg-white p-3 shadow-2xs">
                                    <Truck className="size-5 text-slate-700 shrink-0" />
                                    <div>
                                        <span className="font-semibold block text-slate-900">Standard Delivery Available</span>
                                        <span>Dispatch confirmed during checkout based on country destination.</span>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2.5 rounded-lg border border-slate-200 bg-white p-3 shadow-2xs">
                                    <ShieldCheck className="size-5 text-emerald-600 shrink-0" />
                                    <div>
                                        <span className="font-semibold block text-slate-900">Purchase Protection Guaranteed</span>
                                        <span>Verified pricing calculation direct from central database.</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* Baymard Order Summary Side Panel */}
                        <CartSummary 
                            preview={preview.data} 
                            loading={preview.isPending} 
                            error={preview.isError} 
                            mode={mode} 
                            country={country.name} 
                            orderBlockers={orderBlockers} 
                        />
                    </div>
                )}
            </main>
        </div>
    );
}

function EmptyCart() {
    return (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-20 text-center shadow-2xs">
            <div className="flex size-16 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                <ShoppingCart className="size-8" aria-hidden />
            </div>
            <h2 className="mt-4 text-xl font-bold tracking-tight text-slate-900">Your cart is currently empty</h2>
            <p className="mt-1 max-w-sm text-xs text-slate-500">
                You haven't added any product packs yet. Use your order pad or SKU search to compile your order.
            </p>
            <Button asChild size="lg" className="mt-6 rounded-lg px-6 font-semibold bg-slate-900 text-white hover:bg-slate-800 shadow-2xs">
                <Link href="/order-pad">Go to Order Pad</Link>
            </Button>
        </div>
    );
}

interface LineProps {
    rowNumber: number;
    line: CartLine;
    priced: PreviewLine | undefined;
    pricing: boolean;
    blockers: PreviewBlocker[];
    mode: DisplayMode;
}

function CartRow({ rowNumber, line, priced, pricing, blockers, mode }: LineProps) {
    const hasBlockers = blockers.length > 0;

    return (
        <TableRow className={cn('text-xs transition-colors', hasBlockers ? 'bg-amber-50/60 hover:bg-amber-50' : 'hover:bg-slate-50/80')}>
            <TableCell className="w-10 py-3.5 text-center font-mono text-slate-400 tabular-nums">
                {rowNumber}
            </TableCell>
            <TableCell className="w-14 py-3.5 align-top">
                <Thumbnail url={line.sku.thumbnail_url} />
            </TableCell>
            <TableCell className="min-w-[220px] py-3.5 align-top">
                <ProductName line={line} />
                <LineBlockers blockers={blockers} />
            </TableCell>
            <TableCell className="whitespace-nowrap py-3.5 align-top">
                <div className="font-semibold text-slate-800">{line.pack.label}</div>
                {line.pack.base_units > 1 && (
                    <div className="mt-0.5 inline-flex items-center text-[11px] text-slate-500">
                        <Package className="mr-1 size-3 text-slate-400" />
                        {line.pack.base_units.toLocaleString('en-GB')} base units
                    </div>
                )}
            </TableCell>
            <TableCell className="py-3.5 text-right align-top tabular-nums">
                <PackPrice priced={priced} pricing={pricing} line={line} mode={mode} />
            </TableCell>
            <TableCell className="py-3.5 align-top">
                <div className="flex justify-center">
                    <QuantityEditor line={line} />
                </div>
            </TableCell>
            <TableCell className="py-3.5 text-right align-top font-bold tabular-nums text-slate-900">
                <LineTotal priced={priced} pricing={pricing} mode={mode} />
            </TableCell>
            <TableCell className="w-10 py-3.5 align-top text-center">
                <RemoveButton line={line} />
            </TableCell>
        </TableRow>
    );
}

function CartCard({ rowNumber, line, priced, pricing, blockers, mode }: LineProps) {
    const hasBlockers = blockers.length > 0;

    return (
        <li className={cn(
            'rounded-xl border bg-white p-4 text-xs shadow-2xs transition-all',
            hasBlockers ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200'
        )}>
            <div className="flex gap-3.5">
                <span className="shrink-0 text-xs font-mono font-bold text-slate-400">{rowNumber}</span>
                <Thumbnail url={line.sku.thumbnail_url} className="size-16 rounded-lg" />
                <div className="min-w-0 flex-1">
                    <ProductName line={line} />
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                        <Badge variant="secondary" className="text-[10px] font-medium border-slate-200 bg-slate-100">
                            {line.pack.label}
                        </Badge>
                        <span>·</span>
                        <PackPrice priced={priced} pricing={pricing} line={line} mode={mode} inline />
                    </div>
                </div>
                <RemoveButton line={line} />
            </div>

            <LineBlockers blockers={blockers} />

            <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-3">
                <QuantityEditor line={line} touch />
                <div className="text-right">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Subtotal</span>
                    <span className="text-base font-extrabold text-slate-900 tabular-nums">
                        <LineTotal priced={priced} pricing={pricing} mode={mode} />
                    </span>
                </div>
            </div>
        </li>
    );
}

function Thumbnail({ url, className }: { url: string | null; className?: string }) {
    const [failed, setFailed] = useState(false);

    if (url === null || failed) {
        return (
            <div className={cn('flex size-11 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-400', className)} aria-hidden>
                <ImageOff className="size-4" />
            </div>
        );
    }

    return (
        <img 
            src={url} 
            alt="" 
            loading="lazy" 
            onError={() => setFailed(true)} 
            className={cn('size-11 shrink-0 rounded-lg border border-slate-200 object-cover shadow-2xs', className)} 
        />
    );
}

function ProductName({ line }: { line: CartLine }) {
    return (
        <div className="space-y-0.5">
            <div className="font-bold text-slate-900 leading-tight">{line.sku.name ?? line.sku.sku_code}</div>
            <div className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                <span className="font-mono rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">
                    SKU: {line.sku.sku_code}
                </span>
                {line.sku.variant_label && (
                    <span className="text-slate-500">· {line.sku.variant_label}</span>
                )}
            </div>
        </div>
    );
}

function PackPrice({ priced, pricing, line, mode, inline = false }: { priced: PreviewLine | undefined; pricing: boolean; line: CartLine; mode: DisplayMode; inline?: boolean }) {
    if (priced === undefined || priced.unit_price_net_e4 === null || priced.tax_rate_bp === null) {
        return pricing ? <Skeleton className={cn('h-4 w-14 bg-slate-200', !inline && 'ml-auto')} /> : <span className="text-slate-400">—</span>;
    }

    return (
        <span className="text-slate-700">
            <span className="font-bold text-slate-900">{packPrice(priced.unit_price_net_e4, priced.tax_rate_bp, line.pack.base_units, mode)}</span>
            <span className="text-[11px] text-slate-500"> / pack</span>
        </span>
    );
}

function LineTotal({ priced, pricing, mode }: { priced: PreviewLine | undefined; pricing: boolean; mode: DisplayMode }) {
    const total = priced ? lineTotalMinor(priced, mode) : null;
    if (total === null) {
        return pricing ? <Skeleton className="ml-auto h-4 w-16 bg-slate-200" /> : <span className="text-slate-400">—</span>;
    }

    return <>{formatMinor(total)}</>;
}

function LineBlockers({ blockers }: { blockers: PreviewBlocker[] }) {
    if (blockers.length === 0) return null;

    return (
        <div className="mt-2 space-y-1 rounded-md bg-amber-100/80 p-2 text-xs text-amber-950 border border-amber-200">
            {blockers.map((b, i) => (
                <div key={`${b.code}-${i}`} className="flex items-start gap-1.5">
                    <AlertCircle className="size-3.5 text-amber-700 mt-0.5 shrink-0" />
                    <span className="font-medium">{b.message}</span>
                </div>
            ))}
        </div>
    );
}

function QuantityEditor({ line, touch = false }: { line: CartLine; touch?: boolean }) {
    const update = useUpdateCartLine();
    const [draft, setDraft] = useState(String(line.pack_qty));

    useEffect(() => setDraft(String(line.pack_qty)), [line.pack_qty]);

    const save = (qty: number) => {
        const next = Math.min(Math.max(1, qty), MAX_PACK_QTY);
        setDraft(String(next));
        if (next !== line.pack_qty) {
            update.mutate({ id: line.id, pack_qty: next });
        }
    };

    const commitDraft = () => {
        const parsed = Number.parseInt(draft, 10);
        if (Number.isSafeInteger(parsed) && parsed > 0) {
            save(parsed);
        } else {
            setDraft(String(line.pack_qty));
        }
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            commitDraft();
        } else if (e.key === 'Escape') {
            setDraft(String(line.pack_qty));
        }
    };

    const size = touch ? 'size-10' : 'size-7';

    return (
        <div>
            <div className="inline-flex items-center rounded-lg border border-slate-300 bg-white shadow-2xs" aria-busy={update.isPending}>
                <Button 
                    type="button" 
                    variant="ghost" 
                    size="icon" 
                    className={cn(size, 'rounded-r-none hover:bg-slate-100 text-slate-700')} 
                    onClick={() => save(line.pack_qty - 1)} 
                    disabled={line.pack_qty <= 1 || update.isPending} 
                    aria-label="Reduce quantity" 
                    tabIndex={-1}
                >
                    <Minus className="size-3" />
                </Button>
                <Input
                    inputMode="numeric"
                    pattern="[0-9]*"
                    aria-label={`Quantity of ${line.sku.sku_code} in ${line.pack.label}`}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value.replace(/\D/g, ''))}
                    onBlur={commitDraft}
                    onKeyDown={onKeyDown}
                    className={cn(
                        'border-0 px-1 text-center font-bold tabular-nums focus-visible:ring-0 shadow-none focus-visible:bg-slate-50', 
                        touch ? 'h-10 w-16 text-base' : 'h-7 w-12 text-xs'
                    )}
                />
                <Button 
                    type="button" 
                    variant="ghost" 
                    size="icon" 
                    className={cn(size, 'rounded-l-none hover:bg-slate-100 text-slate-700')} 
                    onClick={() => save(line.pack_qty + 1)} 
                    disabled={update.isPending} 
                    aria-label="Increase quantity" 
                    tabIndex={-1}
                >
                    <Plus className="size-3" />
                </Button>
            </div>
            {update.isError && <p className="mt-1 text-[11px] font-semibold text-red-600">{update.error.message}</p>}
        </div>
    );
}

function RemoveButton({ line }: { line: CartLine }) {
    const remove = useRemoveCartLine();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-8 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors"
            onClick={() => remove.mutate(line.id)}
            disabled={remove.isPending}
            aria-label={`Remove ${line.sku.sku_code} from cart`}
        >
            <Trash2 className="size-4" />
        </Button>
    );
}

function CartSummary({ preview, loading, error, mode, country, orderBlockers }: { preview: CheckoutPreview | undefined; loading: boolean; error: boolean; mode: DisplayMode; country: string; orderBlockers: PreviewBlocker[] }) {
    return (
        <aside className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs lg:sticky lg:top-8" aria-label="Order summary">
            <h2 className="text-lg font-bold text-slate-900 border-b border-slate-200 pb-3">Order Summary</h2>
            
            {preview === undefined ? (
                error ? (
                    <div className="py-4 text-xs font-semibold text-red-600 flex items-center gap-2">
                        <AlertCircle className="size-4 shrink-0" />
                        <span>Calculation unavailable. Try refreshing.</span>
                    </div>
                ) : loading ? (
                    <div className="space-y-3 py-4">
                        <Skeleton className="h-4 w-full bg-slate-200" />
                        <Skeleton className="h-4 w-3/4 bg-slate-200" />
                        <Skeleton className="h-8 w-1/2 bg-slate-200" />
                    </div>
                ) : null
            ) : (
                <dl className="divide-y divide-slate-100 py-3 text-xs tabular-nums" aria-live="polite">
                    {totalsRows({ ...preview, spend_break_discount_minor: preview.spend_break?.discount_minor ?? 0 }, mode).map((row) => (
                        <div key={row.label} className="flex justify-between items-center py-2.5">
                            <dt className={cn(row.tone === 'strong' ? 'text-sm font-bold text-slate-900' : 'text-slate-600 flex items-center gap-1')}>
                                {row.label}
                                {row.label.includes('VAT') && (
                                    <span title="VAT calculated based on destination country">
                                        <HelpCircle className="size-3 text-slate-400 inline" aria-hidden />
                                    </span>
                                )}
                            </dt>
                            <dd className={cn(
                                row.tone === 'strong' && 'text-xl font-extrabold text-slate-900', 
                                row.tone === 'discount' && 'text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200', 
                                row.tone === 'muted' && 'text-slate-500'
                            )}>
                                {row.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {/* Tax & Shipping Transparency Disclaimer */}
            <div className="mt-1 rounded-lg bg-slate-50 p-3 text-[11px] text-slate-600 border border-slate-200/80">
                <p className="flex items-start gap-1.5">
                    <Check className="size-3.5 text-emerald-600 mt-0.5 shrink-0" />
                    <span>Prices calculated using estimated delivery to <strong>{country}</strong>. Taxes and shipping options confirmed at checkout.</span>
                </p>
            </div>

            {/* Validation Warnings */}
            {orderBlockers.length > 0 && (
                <div className="mt-4 space-y-1.5 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950">
                    <span className="font-bold block">Attention Required:</span>
                    {orderBlockers.map((b, i) => (
                        <div key={`${b.code}-${i}`} className="flex items-start gap-1.5">
                            <AlertCircle className="size-3.5 text-amber-700 mt-0.5 shrink-0" />
                            <span>{b.message}</span>
                        </div>
                    ))}
                </div>
            )}

            {/* Primary Action Group */}
            <div className="mt-6 space-y-3">
                <Button 
                    size="lg" 
                    className="w-full h-12 text-sm font-bold bg-slate-900 hover:bg-slate-800 text-white shadow-xs rounded-lg flex items-center justify-center gap-2" 
                    onClick={() => router.visit('/checkout')}
                >
                    <Lock className="size-4 text-slate-400" />
                    Proceed to Checkout
                </Button>
                
                <Button asChild variant="outline" size="lg" className="w-full h-11 text-xs font-semibold text-slate-700 border-slate-300 hover:bg-slate-50 rounded-lg">
                    <Link href="/order-pad">Continue Shopping</Link>
                </Button>
            </div>

            {/* Baymard Security Trust Badge Block */}
            <div className="mt-6 border-t border-slate-200 pt-4 text-center">
                <div className="inline-flex items-center justify-center gap-1.5 text-[11px] font-semibold text-slate-500">
                    <Lock className="size-3 text-slate-400" /> 256-Bit SSL Encrypted Checkout
                </div>
                <p className="mt-1 text-[10px] text-slate-400">Need help with this order? Contact account support.</p>
            </div>
        </aside>
    );
}

function lineIdsOf(blocker: PreviewBlocker): string[] {
    const one = blocker.meta.cart_line_id;
    const many = blocker.meta.cart_line_ids;
    return [...(typeof one === 'string' ? [one] : []), ...(Array.isArray(many) ? many.filter((id): id is string => typeof id === 'string') : [])];
}

function lineBlockers(blockers: PreviewBlocker[]): Map<string, PreviewBlocker[]> {
    const map = new Map<string, PreviewBlocker[]>();
    for (const b of blockers) {
        for (const id of lineIdsOf(b)) {
            map.set(id, [...(map.get(id) ?? []), b]);
        }
    }
    return map;
}