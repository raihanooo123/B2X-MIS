/**
 * The cart (06 §8 `/cart`): the server-side cart's lines with row
 * numbers, thumbnail, pack, price per pack, an editable quantity and the
 * line total; remove a line; running totals; on to checkout.
 *
 * Lines come from `/cart`, figures from `/checkout/preview` — the
 * server's own totals, re-run on every change (preview is side-effect
 * free, 06 §9.2). Nothing is priced here. Line totals include any spend
 * discount, so they add up exactly to the totals beside them.
 *
 * VAT depends on the delivery country, chosen at checkout; until then the
 * cart prices for `estimate_country` and says so.
 */
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ImageOff, Minus, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState, type KeyboardEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
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

/** 06 §9.1 / AddCartLineRequest's own ceiling. */
const MAX_PACK_QTY = 1_000_000;

/** Blockers that are about the buyer, not the cart — checkout explains them. */
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
        <>
            <Head title="Your cart" />
            <div className="mx-auto max-w-[1200px] px-4 py-4">
                <header className="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="flex items-center gap-3">
                        <Link href="/order-pad" className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
                            <ArrowLeft className="size-4" aria-hidden /> Order pad
                        </Link>
                        <h1 className="text-lg font-semibold tracking-tight">Your cart</h1>
                    </div>
                    <AccountMenu />
                </header>

                {cart.isPending ? (
                    <div className="space-y-2">
                        {Array.from({ length: 4 }, (_, i) => (
                            <Skeleton key={i} className="h-14 w-full" />
                        ))}
                    </div>
                ) : cart.isError ? (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        Your cart couldn't be loaded. <button className="underline" onClick={() => void cart.refetch()}>Try again</button>
                    </p>
                ) : lines.length === 0 ? (
                    <EmptyCart />
                ) : (
                    <div className="grid gap-6 lg:grid-cols-[1fr_320px] lg:items-start">
                        <section aria-label="Cart lines">
                            {desktop ? (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="text-xs hover:bg-transparent">
                                                <TableHead className="h-8 w-10 pr-0 text-right">#</TableHead>
                                                <TableHead className="h-8 w-12">
                                                    <span className="sr-only">Image</span>
                                                </TableHead>
                                                <TableHead className="h-8">Product</TableHead>
                                                <TableHead className="h-8">Pack</TableHead>
                                                <TableHead className="h-8 text-right">Price ({vatLabel(mode)})</TableHead>
                                                <TableHead className="h-8">Qty</TableHead>
                                                <TableHead className="h-8 text-right">Line</TableHead>
                                                <TableHead className="h-8 w-10">
                                                    <span className="sr-only">Remove</span>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
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
                                <ol className="space-y-2">
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
                        </section>

                        <CartSummary preview={preview.data} loading={preview.isPending} error={preview.isError} mode={mode} country={country.name} orderBlockers={orderBlockers} />
                    </div>
                )}
            </div>
        </>
    );
}

function EmptyCart() {
    return (
        <div className="flex flex-col items-center rounded-md border border-dashed px-6 py-16 text-center">
            <ShoppingCart className="mb-3 size-8 text-muted-foreground" aria-hidden />
            <h2 className="font-medium">Your cart is empty</h2>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">Add quantities on the order pad, then "Add to cart".</p>
            <Button asChild className="mt-4 h-11">
                <Link href="/order-pad">Go to the order pad</Link>
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
    return (
        <TableRow className={cn('text-[13px]', blockers.length > 0 && 'bg-amber-50/60 hover:bg-amber-50')}>
            <TableCell className="w-10 py-2 pr-0 text-right align-top tabular-nums text-muted-foreground">{rowNumber}</TableCell>
            <TableCell className="w-12 py-2 align-top">
                <Thumbnail url={line.sku.thumbnail_url} />
            </TableCell>
            <TableCell className="min-w-48 py-2 align-top">
                <ProductName line={line} />
                <LineBlockers blockers={blockers} />
            </TableCell>
            <TableCell className="whitespace-nowrap py-2 align-top">
                {line.pack.label}
                {line.pack.base_units > 1 && <span className="block text-xs text-muted-foreground">{line.pack.base_units.toLocaleString('en-GB')} units</span>}
            </TableCell>
            <TableCell className="py-2 text-right align-top tabular-nums">
                <PackPrice priced={priced} pricing={pricing} line={line} mode={mode} />
            </TableCell>
            <TableCell className="py-2 align-top">
                <QuantityEditor line={line} />
            </TableCell>
            <TableCell className="py-2 text-right align-top font-medium tabular-nums">
                <LineTotal priced={priced} pricing={pricing} mode={mode} />
            </TableCell>
            <TableCell className="py-2 align-top">
                <RemoveButton line={line} />
            </TableCell>
        </TableRow>
    );
}

function CartCard({ rowNumber, line, priced, pricing, blockers, mode }: LineProps) {
    return (
        <li className={cn('rounded-lg border bg-card p-3 text-[13px] shadow-sm', blockers.length > 0 && 'border-amber-300 bg-amber-50/60')}>
            <div className="flex gap-3">
                <span className="w-5 shrink-0 pt-0.5 text-right text-xs tabular-nums text-muted-foreground">{rowNumber}</span>
                <Thumbnail url={line.sku.thumbnail_url} className="size-14" />
                <div className="min-w-0 flex-1">
                    <ProductName line={line} />
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {line.pack.label} · <PackPrice priced={priced} pricing={pricing} line={line} mode={mode} inline />
                    </div>
                </div>
                <RemoveButton line={line} />
            </div>
            <LineBlockers blockers={blockers} />
            <div className="mt-3 flex items-center justify-between gap-3">
                <QuantityEditor line={line} touch />
                <span className="text-right tabular-nums">
                    <span className="text-xs text-muted-foreground">Line </span>
                    <span className="font-semibold">
                        <LineTotal priced={priced} pricing={pricing} mode={mode} />
                    </span>
                </span>
            </div>
        </li>
    );
}

function Thumbnail({ url, className }: { url: string | null; className?: string }) {
    const [failed, setFailed] = useState(false);

    if (url === null || failed) {
        return (
            <div className={cn('flex size-10 shrink-0 items-center justify-center rounded border bg-muted text-muted-foreground', className)} aria-hidden>
                <ImageOff className="size-4" />
            </div>
        );
    }

    return <img src={url} alt="" loading="lazy" onError={() => setFailed(true)} className={cn('size-10 shrink-0 rounded border object-cover', className)} />;
}

function ProductName({ line }: { line: CartLine }) {
    return (
        <>
            <div className="font-medium leading-tight">{line.sku.name ?? line.sku.sku_code}</div>
            <div className="text-xs leading-tight text-muted-foreground">
                <span className="font-mono">{line.sku.sku_code}</span>
                {line.sku.variant_label && ` · ${line.sku.variant_label}`}
            </div>
        </>
    );
}

function PackPrice({ priced, pricing, line, mode, inline = false }: { priced: PreviewLine | undefined; pricing: boolean; line: CartLine; mode: DisplayMode; inline?: boolean }) {
    if (priced === undefined || priced.unit_price_net_e4 === null || priced.tax_rate_bp === null) {
        return pricing ? <Skeleton className={cn('h-4 w-14', !inline && 'ml-auto')} /> : <span className="text-muted-foreground">—</span>;
    }

    return (
        <span>
            {packPrice(priced.unit_price_net_e4, priced.tax_rate_bp, line.pack.base_units, mode)}
            <span className="text-muted-foreground">/{line.pack.base_units > 1 ? 'pack' : 'each'}</span>
        </span>
    );
}

function LineTotal({ priced, pricing, mode }: { priced: PreviewLine | undefined; pricing: boolean; mode: DisplayMode }) {
    const total = priced ? lineTotalMinor(priced, mode) : null;
    if (total === null) {
        return pricing ? <Skeleton className="ml-auto h-4 w-16" /> : <span className="text-muted-foreground">—</span>;
    }

    return <>{formatMinor(total)}</>;
}

function LineBlockers({ blockers }: { blockers: PreviewBlocker[] }) {
    if (blockers.length === 0) {
        return null;
    }

    return (
        <ul className="mt-1 space-y-0.5 text-xs text-amber-800">
            {blockers.map((b, i) => (
                <li key={`${b.code}-${i}`}>{b.message}</li>
            ))}
        </ul>
    );
}

/**
 * Quantity in packs. −/+ save at once; typed values save on Enter or
 * when the field loses focus. Clearing the field and leaving restores the
 * saved quantity — removing a line is the bin button, deliberately.
 */
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

    const size = touch ? 'size-11 [&_svg]:size-5' : 'size-8';

    return (
        <div>
            <div className="flex items-center" aria-busy={update.isPending}>
                <Button type="button" variant="outline" size="icon" className={cn(size, 'rounded-r-none')} onClick={() => save(line.pack_qty - 1)} disabled={line.pack_qty <= 1 || update.isPending} aria-label="One fewer" tabIndex={-1}>
                    <Minus />
                </Button>
                <Input
                    inputMode="numeric"
                    pattern="[0-9]*"
                    aria-label={`Quantity of ${line.sku.sku_code} in ${line.pack.label}`}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value.replace(/\D/g, ''))}
                    onBlur={commitDraft}
                    onKeyDown={onKeyDown}
                    className={cn('rounded-none border-x-0 px-1 text-center tabular-nums shadow-none', touch ? 'h-11 w-16 text-base' : 'h-8 w-14 text-[13px]')}
                />
                <Button type="button" variant="outline" size="icon" className={cn(size, 'rounded-l-none')} onClick={() => save(line.pack_qty + 1)} disabled={update.isPending} aria-label="One more" tabIndex={-1}>
                    <Plus />
                </Button>
            </div>
            {update.isError && <p className="mt-0.5 text-xs text-red-700">{update.error.message}</p>}
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
            className="size-11 text-muted-foreground hover:text-red-700 md:size-8"
            onClick={() => remove.mutate(line.id)}
            disabled={remove.isPending}
            aria-label={`Remove ${line.sku.sku_code} from the cart`}
        >
            <Trash2 />
        </Button>
    );
}

function CartSummary({ preview, loading, error, mode, country, orderBlockers }: { preview: CheckoutPreview | undefined; loading: boolean; error: boolean; mode: DisplayMode; country: string; orderBlockers: PreviewBlocker[] }) {
    return (
        <aside className="rounded-md border p-4 lg:sticky lg:top-4" aria-label="Order summary">
            <h2 className="mb-3 text-sm font-semibold">Summary</h2>
            {preview === undefined ? (
                error ? (
                    <p className="text-sm text-red-700">Totals couldn't be calculated. Try again in a moment.</p>
                ) : loading ? (
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-2/3" />
                    </div>
                ) : null
            ) : (
                <dl className="space-y-1.5 text-sm tabular-nums" aria-live="polite">
                    {totalsRows({ ...preview, spend_break_discount_minor: preview.spend_break?.discount_minor ?? 0 }, mode).map((row) => (
                        <div key={row.label} className="flex justify-between gap-4">
                            <dt className={cn(row.tone === 'strong' ? 'font-semibold' : 'text-muted-foreground')}>{row.label}</dt>
                            <dd className={cn(row.tone === 'strong' && 'text-base font-semibold', row.tone === 'discount' && 'text-emerald-700', row.tone === 'muted' && 'text-muted-foreground')}>{row.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            <p className="mt-2 text-xs text-muted-foreground">
                VAT shown for delivery to {country}; confirmed at checkout. Delivery is confirmed at checkout.
            </p>

            {orderBlockers.length > 0 && (
                <ul className="mt-3 space-y-1 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    {orderBlockers.map((b, i) => (
                        <li key={`${b.code}-${i}`}>{b.message}</li>
                    ))}
                </ul>
            )}

            <div className="mt-4 flex flex-col gap-2">
                <Button className="h-11" onClick={() => router.visit('/checkout')}>
                    Checkout
                </Button>
                <Button asChild variant="outline" className="h-11">
                    <Link href="/order-pad">Continue shopping</Link>
                </Button>
            </div>
        </aside>
    );
}

/** The cart lines a blocker concerns (`meta.cart_line_id` or `meta.cart_line_ids`). */
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
