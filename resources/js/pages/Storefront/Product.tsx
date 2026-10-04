/**
 * Product page (05.15 §5.3): gallery, brand, name, SKU, price inc or ex VAT,
 * variant and pack choice, the volume-break table, a quantity stepper that
 * respects MOQ, and a stock label (never a figure). *Add to basket* uses
 * the cart API (06 §8), for guests too.
 *
 * Every price here is for display: the basket and checkout price the line
 * on the server, so what the buyer pays is always the server's figure.
 */
import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronRight, ImageOff, Minus, Plus, ShoppingBag } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { ProductCard, StockBadge, type ProductCardData, type StockLabel } from '@/components/storefront/ProductCard';
import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { useAddCartLine } from '@/lib/api/orderPad';
import { unitPriceE4, vatLabel, type DisplayMode } from '@/lib/cart/display';
import { formatMinor, lineNetMinor, multiplyInts } from '@/lib/money';
import { storefrontLinks } from '@/lib/storefront/links';
import { shelfPrice } from '@/lib/storefront/price';
import { recordRecentlyViewed } from '@/lib/storefront/recentlyViewed';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface Variant {
    id: string;
    sku_code: string;
    label: string | null;
    moq_base_qty: number;
    increment_base_qty: number;
    max_base_qty: number | null;
    packs: { code: string; label: string; base_units: number }[];
    default_pack_code: string | null;
    price: { unit_net_e4: number; tax_rate_bp: number; breaks: { min_base_qty: number; unit_net_e4: number }[] } | null;
    stock: StockLabel;
    stock_left: number | null;
}

interface ProductData {
    id: string;
    name: string;
    slug: string;
    brand: { name: string; slug: string } | null;
    short_description: string | null;
    description: string | null;
    specifications: { label: string; value: string }[];
    meta_title: string | null;
    meta_description: string | null;
    rrp_minor: number | null;
    breadcrumb: { name: string; slug: string }[];
    images: { url: string; alt: string | null }[];
    variants: Variant[];
    related: ProductCardData[];
}

export default function ProductPage({ shell, product }: { shell: ShellProps; product: ProductData }) {
    const { price_display } = usePage<SharedProps>().props;
    const mode = price_display.mode;

    useEffect(() => {
        recordRecentlyViewed({ slug: product.slug, name: product.name, thumbnail_url: product.images[0]?.url ?? null });
    }, [product.slug, product.name, product.images]);

    return (
        <StorefrontLayout title={product.meta_title ?? product.name} description={product.meta_description ?? product.short_description ?? undefined} shell={shell}>
            <div className="mx-auto max-w-7xl px-4 py-6">
                <Breadcrumb product={product} />

                <div className="mt-4 grid gap-8 lg:grid-cols-2 lg:gap-12">
                    <Gallery images={product.images} name={product.name} />
                    <BuyBox product={product} mode={mode} />
                </div>

                {(product.description || product.specifications.length > 0) && (
                    <div className="mt-12 grid gap-8 border-t pt-8 lg:grid-cols-2 lg:gap-12">
                        {product.description && (
                            <section aria-labelledby="description">
                                <h2 id="description" className="text-lg font-semibold">
                                    Description
                                </h2>
                                <p className="mt-3 whitespace-pre-line leading-relaxed text-muted-foreground">{product.description}</p>
                            </section>
                        )}
                        {product.specifications.length > 0 && (
                            <section aria-labelledby="specifications">
                                <h2 id="specifications" className="text-lg font-semibold">
                                    Specifications
                                </h2>
                                <dl className="mt-3 divide-y rounded-lg border text-sm">
                                    {product.specifications.map((row) => (
                                        <div key={row.label} className="grid grid-cols-2 gap-4 px-4 py-2.5">
                                            <dt className="text-muted-foreground">{row.label}</dt>
                                            <dd className="font-medium">{row.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </section>
                        )}
                    </div>
                )}

                {product.related.length > 0 && (
                    <section aria-labelledby="related" className="mt-12 border-t pt-8">
                        <h2 id="related" className="text-lg font-semibold">
                            You may also need
                        </h2>
                        <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                            {product.related.slice(0, 4).map((card) => (
                                <ProductCard key={card.id} card={card} mode={mode} />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </StorefrontLayout>
    );
}

function Breadcrumb({ product }: { product: ProductData }) {
    return (
        <nav aria-label="Breadcrumb">
            <ol className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
                <li>
                    <Link href="/" className="hover:text-foreground">
                        Home
                    </Link>
                </li>
                {product.breadcrumb.map((crumb) => (
                    <li key={crumb.slug} className="flex items-center gap-1">
                        <ChevronRight className="size-3.5" aria-hidden />
                        <Link href={storefrontLinks.category(crumb.slug)} className="hover:text-foreground">
                            {crumb.name}
                        </Link>
                    </li>
                ))}
                <li className="flex items-center gap-1">
                    <ChevronRight className="size-3.5" aria-hidden />
                    <span aria-current="page" className="line-clamp-1 text-foreground">
                        {product.name}
                    </span>
                </li>
            </ol>
        </nav>
    );
}

function Gallery({ images, name }: { images: ProductData['images']; name: string }) {
    const [active, setActive] = useState(0);
    const current = images[active];

    return (
        <div>
            <div className="aspect-square overflow-hidden rounded-3xl border border-border/70 bg-muted/20 p-6">
                {current ? (
                    <img src={current.url} alt={current.alt ?? name} className="size-full object-contain" />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <ImageOff className="size-10" aria-hidden />
                        <span className="sr-only">No image</span>
                    </div>
                )}
            </div>
            {images.length > 1 && (
                <ul className="mt-3 flex gap-2 overflow-x-auto" aria-label="Product images">
                    {images.map((image, i) => (
                        <li key={image.url}>
                            <button
                                type="button"
                                onClick={() => setActive(i)}
                                aria-pressed={i === active}
                                aria-label={`Show image ${i + 1}`}
                                className={cn('size-16 overflow-hidden rounded-lg border-2', i === active ? 'border-primary' : 'border-transparent')}
                            >
                                <img src={image.url} alt="" className="size-full object-contain" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** The break that applies at `baseQty`: the highest minimum at or below it. */
function unitAt(price: NonNullable<Variant['price']>, baseQty: number): number {
    let unit = price.unit_net_e4;
    for (const b of price.breaks) {
        if (b.min_base_qty <= baseQty) {
            unit = b.unit_net_e4;
        }
    }
    return unit;
}

function BuyBox({ product, mode }: { product: ProductData; mode: DisplayMode }) {
    const [variantId, setVariantId] = useState(product.variants[0].id);
    const variant = product.variants.find((v) => v.id === variantId) ?? product.variants[0];
    const [packCode, setPackCode] = useState(variant.default_pack_code ?? variant.packs[0]?.code ?? null);
    const pack = variant.packs.find((p) => p.code === packCode) ?? variant.packs[0];

    const minPacks = pack ? Math.max(1, Math.ceil(variant.moq_base_qty / pack.base_units)) : 1;
    const [qty, setQty] = useState(minPacks);
    const packQty = Math.max(qty, minPacks);
    const baseQty = pack ? multiplyInts(packQty, pack.base_units) : 0;

    const add = useAddCartLine();
    const [added, setAdded] = useState(false);
    const unavailable = variant.stock === 'out_of_stock' || variant.price === null || !pack;

    const chooseVariant = (v: Variant) => {
        setVariantId(v.id);
        setPackCode(v.default_pack_code ?? v.packs[0]?.code ?? null);
        setAdded(false);
    };

    // 05.15 §5.3a: on small screens, a sticky bar once the main button scrolls away.
    const mainButton = useRef<HTMLButtonElement>(null);
    const [mainVisible, setMainVisible] = useState(true);
    useEffect(() => {
        const el = mainButton.current;
        if (!el || typeof IntersectionObserver === 'undefined') return;
        const observer = new IntersectionObserver(([entry]) => setMainVisible(entry.isIntersecting));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const submit = () => {
        if (!pack || unavailable) return;
        setAdded(false);
        add.mutate(
            { sku_id: variant.id, pack_code: pack.code, pack_qty: packQty },
            {
                onSuccess: () => {
                    setAdded(true);
                    router.reload({ only: ['shell'] });
                },
            },
        );
    };

    const priceRows = useMemo(() => {
        if (!variant.price) return [];
        const ladder = variant.price.breaks.length > 0 ? variant.price.breaks : [{ min_base_qty: 1, unit_net_e4: variant.price.unit_net_e4 }];
        // The rung in force: the last one whose minimum the quantity reaches.
        const active = ladder.reduce((found, b, i) => (b.min_base_qty <= baseQty ? i : found), -1);
        return ladder.map((b, i) => ({ ...b, applies: i === active }));
    }, [variant, baseQty]);

    return (
        <div>
            {product.brand && <p className="text-sm font-medium uppercase tracking-wide text-muted-foreground">{product.brand.name}</p>}
            <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{product.name}</h1>
            <p className="mt-1 text-sm text-muted-foreground">SKU {variant.sku_code}</p>
            {product.short_description && <p className="mt-4 leading-relaxed text-muted-foreground">{product.short_description}</p>}

            <div className="mt-6 rounded-2xl border border-border/70 bg-background p-5 shadow-sm sm:p-6">
                {variant.price ? (
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p className="text-4xl font-semibold tracking-tight tabular-nums">{shelfPrice(unitAt(variant.price, baseQty), variant.price.tax_rate_bp, mode)}</p>
                            <p className="text-sm text-muted-foreground">each, {vatLabel(mode)}</p>
                            {product.rrp_minor !== null && <p className="mt-1 text-sm text-muted-foreground">RRP {formatMinor(product.rrp_minor)}</p>}
                        </div>
                        <StockBadge stock={variant.stock} left={variant.stock_left} />
                    </div>
                ) : (
                    <p className="text-muted-foreground">This item is not available to buy online. Please contact us.</p>
                )}

                {product.variants.length > 1 && (
                    <fieldset className="mt-6">
                        <legend className="mb-2 text-sm font-semibold">Option</legend>
                        <div className="flex flex-wrap gap-2">
                            {product.variants.map((v) => (
                                <button
                                    key={v.id}
                                    type="button"
                                    aria-pressed={v.id === variant.id}
                                    onClick={() => chooseVariant(v)}
                                    className={cn('min-h-11 rounded-lg border px-4 text-sm font-medium', v.id === variant.id ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted')}
                                >
                                    {v.label ?? v.sku_code}
                                </button>
                            ))}
                        </div>
                    </fieldset>
                )}

                {variant.packs.length > 1 && variant.price && (
                    <fieldset className="mt-6">
                        <legend className="mb-2 text-sm font-semibold">Buy by</legend>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {variant.packs.map((p) => {
                                const packPriceMinor = lineNetMinor(unitPriceE4(unitAt(variant.price!, p.base_units), variant.price!.tax_rate_bp, mode), p.base_units);
                                return (
                                    <label
                                        key={p.code}
                                        className={cn(
                                            'flex cursor-pointer flex-col rounded-lg border p-3 text-sm transition-colors',
                                            p.code === pack?.code ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <input type="radio" name="pack" value={p.code} checked={p.code === pack?.code} onChange={() => setPackCode(p.code)} className="sr-only" />
                                        <span className="font-medium">{p.label}</span>
                                        <span className="text-muted-foreground">
                                            {p.base_units === 1 ? 'Single item' : `${p.base_units} items`} · {formatMinor(packPriceMinor)}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                )}

                {variant.price && pack && (
                    <div className="mt-6 flex flex-wrap items-center gap-3">
                        <div className="inline-flex items-center rounded-lg border" role="group" aria-label="Quantity">
                            <button type="button" onClick={() => setQty(Math.max(minPacks, packQty - 1))} disabled={packQty <= minPacks} className="inline-flex size-11 items-center justify-center disabled:opacity-40" aria-label="One fewer">
                                <Minus className="size-4" aria-hidden />
                            </button>
                            <input
                                type="number"
                                inputMode="numeric"
                                min={minPacks}
                                value={packQty}
                                onChange={(e) => setQty(Math.max(minPacks, Number.parseInt(e.target.value, 10) || minPacks))}
                                aria-label={`Quantity in ${pack.label.toLowerCase()}`}
                                className="h-11 w-16 border-x bg-transparent text-center font-semibold tabular-nums outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
                            />
                            <button type="button" onClick={() => setQty(packQty + 1)} className="inline-flex size-11 items-center justify-center" aria-label="One more">
                                <Plus className="size-4" aria-hidden />
                            </button>
                        </div>
                        <button
                            ref={mainButton}
                            type="button"
                            onClick={submit}
                            disabled={unavailable || add.isPending}
                            className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary px-6 font-semibold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            <ShoppingBag className="size-5" aria-hidden />
                            {variant.stock === 'out_of_stock' ? 'Out of stock' : add.isPending ? 'Adding…' : 'Add to basket'}
                        </button>
                    </div>
                )}

                {variant.price && pack && !mainVisible && (
                    <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 px-4 py-3 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] backdrop-blur lg:hidden">
                        <div className="mx-auto flex max-w-7xl items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <p className="line-clamp-1 text-sm font-medium">{product.name}</p>
                                <p className="text-sm font-semibold tabular-nums">
                                    {shelfPrice(unitAt(variant.price, baseQty), variant.price.tax_rate_bp, mode)}{' '}
                                    <span className="text-xs font-normal text-muted-foreground">each, {vatLabel(mode)}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={submit}
                                disabled={unavailable || add.isPending}
                                className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-lg bg-primary px-5 font-semibold text-primary-foreground disabled:opacity-50"
                            >
                                <ShoppingBag className="size-5" aria-hidden />
                                {added ? 'Added' : add.isPending ? 'Adding…' : 'Add'}
                            </button>
                        </div>
                    </div>
                )}

                {pack && variant.price && (
                    <p className="mt-3 text-sm text-muted-foreground">
                        {packQty} × {pack.label.toLowerCase()} = {baseQty} {baseQty === 1 ? 'item' : 'items'}
                        {variant.moq_base_qty > 1 && ` · minimum order ${variant.moq_base_qty} items`}
                    </p>
                )}

                <div aria-live="polite">
                    {added && (
                        <p className="mt-4 flex flex-wrap items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                            <Check className="size-4" aria-hidden /> Added to your basket.
                            <Link href={storefrontLinks.cart()} className="font-semibold underline underline-offset-4">
                                View basket
                            </Link>
                        </p>
                    )}
                    {add.isError && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{add.error.message || 'That could not be added. Please try again.'}</p>}
                </div>
            </div>

            {priceRows.length > 1 && (
                <section aria-labelledby="breaks" className="mt-6">
                    <h2 id="breaks" className="text-sm font-semibold">
                        Buy more, pay less
                    </h2>
                    <table className="mt-2 w-full overflow-hidden rounded-lg border text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th scope="col" className="px-4 py-2 font-medium">
                                    Quantity (items)
                                </th>
                                <th scope="col" className="px-4 py-2 text-right font-medium">
                                    Price each ({vatLabel(mode)})
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {priceRows.map((row) => (
                                <tr key={row.min_base_qty} className={cn(row.applies && 'bg-primary/5 font-semibold')}>
                                    <td className="px-4 py-2">{row.min_base_qty}+</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{shelfPrice(row.unit_net_e4, variant.price!.tax_rate_bp, mode)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}
        </div>
    );
}
