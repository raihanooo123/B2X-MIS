/**
 * Storefront home (05.15 §5.1): a two-column hero (the brand, search and
 * quick department links beside a mosaic of featured products), a compact
 * trust strip, department tiles with a picture and product count, then
 * featured and newest products. Promotional content arrives with the CMS
 * (05.11).
 */
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, BadgePercent, ImageOff, Search, ShieldCheck, Truck } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';

import { ProductCard, type ProductCardData } from '@/components/storefront/ProductCard';
import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { storefrontLinks } from '@/lib/storefront/links';
import { shelfPrice } from '@/lib/storefront/price';
import { readRecentlyViewed, type RecentlyViewedItem } from '@/lib/storefront/recentlyViewed';
import type { SharedProps } from '@/types/shared';

interface Department {
    slug: string;
    name: string;
    product_count: number;
    image_url: string | null;
}

interface HomeProps {
    shell: ShellProps;
    products: ProductCardData[];
    departments: Department[];
}

export default function Home({ shell, products, departments }: HomeProps) {
    const { brand, price_display } = usePage<SharedProps>().props;
    const showcase = products.filter((p) => p.thumbnail_url !== null).slice(0, 4);

    return (
        <StorefrontLayout title={brand.name} description={brand.tagline ?? `Shop online with ${brand.name}.`} shell={shell}>
            <section className="relative overflow-hidden border-b bg-gradient-to-br from-primary/10 via-background to-background">
                <div className="mx-auto grid max-w-7xl items-center gap-10 px-4 py-10 sm:py-14 lg:grid-cols-[1.1fr_1fr] lg:py-16">
                    <div>
                        <p className="inline-flex items-center rounded-full border border-primary/20 bg-background/70 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-primary">
                            Trade and retail
                        </p>
                        <h1 className="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">{brand.name}</h1>
                        <p className="mt-4 max-w-xl text-lg text-muted-foreground">{brand.tagline ?? 'Everyday essentials at wholesale value, delivered across the UK.'}</p>
                        <HeroSearch />
                        {departments.length > 0 && (
                            <div className="mt-5 flex flex-wrap items-center gap-2 text-sm">
                                <span className="text-muted-foreground">Popular:</span>
                                {departments.slice(0, 4).map((d) => (
                                    <Link key={d.slug} href={storefrontLinks.category(d.slug)} className="inline-flex min-h-9 items-center rounded-full border bg-background px-3 font-medium hover:border-primary/50 hover:text-primary">
                                        {d.name}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                    {showcase.length > 0 && (
                        <ul className="grid grid-cols-2 gap-3 pb-6 sm:gap-4" aria-label="Featured products">
                            {showcase.map((card, i) => (
                                <li key={card.id} className={i % 2 === 1 ? 'translate-y-6' : ''}>
                                    <Link href={storefrontLinks.product(card)} className="group block overflow-hidden rounded-2xl border bg-card shadow-sm transition-shadow hover:shadow-lg">
                                        <div className="aspect-[4/3] overflow-hidden bg-muted/40">
                                            <img src={card.thumbnail_url ?? ''} alt="" className="size-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                        </div>
                                        <div className="flex items-center justify-between gap-2 px-3 py-2.5">
                                            <span className="line-clamp-1 text-sm font-medium">{card.name}</span>
                                            {card.price && (
                                                <span className="shrink-0 text-sm font-semibold tabular-nums text-primary">{shelfPrice(card.price.unit_net_e4, card.price.tax_rate_bp, price_display.mode)}</span>
                                            )}
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </section>

            <section aria-label="Why shop with us" className="border-b bg-background">
                <ul className="mx-auto grid max-w-7xl divide-y px-4 text-sm sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    <TrustPoint icon={<Truck className="size-5" aria-hidden />} title="UK delivery" text="Delivery cost shown before you pay" />
                    <TrustPoint icon={<BadgePercent className="size-5" aria-hidden />} title="Pack and case prices" text="Pay less per item when you buy more" />
                    <TrustPoint icon={<ShieldCheck className="size-5" aria-hidden />} title="Secure checkout" text="Card payments processed by Stripe" />
                </ul>
            </section>

            {departments.length > 0 && (
                <section aria-labelledby="departments" className="mx-auto max-w-7xl px-4 pt-12">
                    <SectionHeading id="departments" title="Shop by department" />
                    <ul className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-6">
                        {departments.map((d) => (
                            <li key={d.slug}>
                                <Link href={storefrontLinks.category(d.slug)} className="group block overflow-hidden rounded-xl border bg-card transition-all hover:border-primary/40 hover:shadow-md">
                                    <div className="aspect-[4/3] overflow-hidden bg-muted/40">
                                        {d.image_url ? (
                                            <img src={d.image_url} alt="" loading="lazy" className="size-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                        ) : (
                                            <div className="flex size-full items-center justify-center text-muted-foreground">
                                                <ImageOff className="size-6" aria-hidden />
                                            </div>
                                        )}
                                    </div>
                                    <div className="px-3 py-3">
                                        <p className="font-medium leading-snug group-hover:text-primary">{d.name}</p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {d.product_count} {d.product_count === 1 ? 'product' : 'products'}
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="products" className="mx-auto max-w-7xl px-4 pt-12">
                <SectionHeading
                    id="products"
                    title="Featured and new"
                    action={
                        <Link href="/search" className="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary hover:underline">
                            See all products <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    }
                />
                {products.length > 0 ? (
                    <ul className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-6">
                        {products.map((card) => (
                            <ProductCard key={card.id} card={card} mode={price_display.mode} />
                        ))}
                    </ul>
                ) : (
                    <p className="mt-5 rounded-xl border border-dashed px-6 py-12 text-center text-muted-foreground">New products are on their way. Please check back soon.</p>
                )}
            </section>

            <RecentlyViewed />

            {/* Guests and public customers only: not trade users or staff. */}
            {price_display.can_switch && (
                <section className="mx-auto max-w-7xl px-4 pt-12">
                    <div className="flex flex-col items-start justify-between gap-4 rounded-2xl bg-primary px-6 py-8 text-primary-foreground sm:flex-row sm:items-center sm:px-10">
                        <div>
                            <h2 className="text-lg font-semibold">Buying for a business?</h2>
                            <p className="mt-1 text-sm opacity-90">Open a trade account for trade prices, credit terms and fast bulk ordering.</p>
                        </div>
                        <Link
                            href="/register?type=trade"
                            className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-lg bg-background px-5 text-sm font-semibold text-foreground hover:bg-background/90"
                        >
                            Apply for a trade account <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                </section>
            )}
        </StorefrontLayout>
    );
}

function SectionHeading({ id, title, action }: { id: string; title: string; action?: ReactNode }) {
    return (
        <div className="flex items-end justify-between gap-4">
            <h2 id={id} className="text-xl font-semibold tracking-tight sm:text-2xl">
                {title}
            </h2>
            {action}
        </div>
    );
}

function HeroSearch() {
    const inputId = useId();
    const input = useRef<HTMLInputElement>(null);

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const q = input.current?.value.trim() ?? '';
        if (q !== '') {
            router.visit(storefrontLinks.search(q));
        }
    };

    return (
        <form role="search" onSubmit={submit} className="mt-7 flex max-w-xl gap-2">
            <label htmlFor={inputId} className="sr-only">
                Search products
            </label>
            <div className="relative flex-1">
                <Search className="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
                <input
                    ref={input}
                    id={inputId}
                    type="search"
                    placeholder="What are you looking for?"
                    className="h-12 w-full rounded-lg border bg-background pl-11 pr-4 text-base shadow-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                />
            </div>
            <button type="submit" className="h-12 shrink-0 rounded-lg bg-primary px-6 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90">
                Search
            </button>
        </form>
    );
}

function TrustPoint({ icon, title, text }: { icon: ReactNode; title: string; text: string }) {
    return (
        <li className="flex items-center gap-3 py-4 sm:px-6 sm:first:pl-0">
            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">{icon}</span>
            <span>
                <span className="block font-semibold">{title}</span>
                <span className="block text-muted-foreground">{text}</span>
            </span>
        </li>
    );
}

/** 05.15 §5.3a: this browser's last product pages. Nothing is shown until there are some. */
function RecentlyViewed() {
    const [items, setItems] = useState<RecentlyViewedItem[]>([]);
    useEffect(() => setItems(readRecentlyViewed()), []);

    if (items.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="recently-viewed" className="mx-auto max-w-7xl px-4 pt-12">
            <SectionHeading id="recently-viewed" title="Recently viewed" />
            <ul className="mt-5 flex gap-3 overflow-x-auto pb-2 sm:gap-4">
                {items.map((item) => (
                    <li key={item.slug} className="w-36 shrink-0 sm:w-40">
                        <Link href={storefrontLinks.product(item)} className="group block">
                            <div className="aspect-square overflow-hidden rounded-xl border bg-muted/40">
                                {item.thumbnail_url ? (
                                    <img src={item.thumbnail_url} alt="" loading="lazy" className="size-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                ) : (
                                    <div className="flex size-full items-center justify-center text-muted-foreground">
                                        <ImageOff className="size-6" aria-hidden />
                                    </div>
                                )}
                            </div>
                            <p className="mt-2 line-clamp-2 text-sm font-medium group-hover:text-primary">{item.name}</p>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
