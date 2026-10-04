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
            <section className="mx-auto max-w-7xl px-4 pt-4 sm:pt-6">
                <div className="relative isolate overflow-hidden rounded-[2rem] bg-zinc-950 text-white">
                    {/* Brand-coloured glow: the hero follows the business's own --primary. */}
                    <div aria-hidden className="absolute -left-32 -top-40 -z-10 size-[36rem] rounded-full bg-primary/50 blur-[120px]" />
                    <div aria-hidden className="absolute -bottom-48 right-0 -z-10 size-[28rem] rounded-full bg-primary/25 blur-[120px]" />
                    <div className="grid items-center gap-10 px-6 py-10 sm:px-10 sm:py-14 lg:grid-cols-[1.15fr_1fr] lg:gap-14 lg:px-14 lg:py-16">
                        <div>
                            <p className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-white/90 ring-1 ring-inset ring-white/15">
                                <span className="size-1.5 rounded-full bg-emerald-400" aria-hidden />
                                Trade and retail
                            </p>
                            <h1 className="mt-5 max-w-xl text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">{brand.name}</h1>
                            <p className="mt-4 max-w-lg text-base leading-relaxed text-zinc-300 sm:text-lg">{brand.tagline ?? 'Everyday essentials at wholesale value, delivered across the UK.'}</p>
                            <HeroSearch />
                            {departments.length > 0 && (
                                <div className="mt-5 hidden flex-wrap items-center gap-2 text-sm sm:flex">
                                    <span className="text-zinc-400">Popular:</span>
                                    {departments.slice(0, 4).map((d) => (
                                        <Link key={d.slug} href={storefrontLinks.category(d.slug)} className="inline-flex min-h-11 items-center rounded-full bg-white/10 px-4 font-medium text-white ring-1 ring-inset ring-white/15 transition-colors hover:bg-white/20 md:min-h-9">
                                            {d.name}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>

                        {showcase.length > 0 && (
                            <ul className="grid grid-cols-2 gap-3 sm:gap-4 lg:pb-6" aria-label="Featured products">
                                {showcase.map((card, i) => (
                                    <li key={card.id} className={i % 2 === 1 ? 'lg:translate-y-6' : ''}>
                                        <Link href={storefrontLinks.product(card)} className="group block overflow-hidden rounded-2xl bg-white text-zinc-950 shadow-2xl shadow-black/30 ring-1 ring-white/10 transition-transform duration-300 motion-safe:hover:-translate-y-1">
                                            <div className="aspect-[4/3] overflow-hidden bg-zinc-100">
                                                <img src={card.thumbnail_url ?? ''} alt="" className="size-full object-contain p-4 mix-blend-multiply transition-transform duration-500 motion-reduce:transition-none motion-safe:group-hover:scale-105" />
                                            </div>
                                            <div className="flex items-center justify-between gap-2 px-3 py-2.5 sm:px-4 sm:py-3">
                                                <span className="line-clamp-1 text-xs font-medium sm:text-sm">{card.name}</span>
                                                {card.price && (
                                                    <span className="shrink-0 text-sm font-bold tabular-nums">{shelfPrice(card.price.unit_net_e4, card.price.tax_rate_bp, price_display.mode)}</span>
                                                )}
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </section>

            <section aria-label="Why shop with us" className="mx-auto max-w-7xl px-4 pt-4 sm:pt-6">
                <ul className="grid gap-3 text-sm sm:grid-cols-3 sm:gap-4">
                    <TrustPoint icon={<Truck className="size-5" aria-hidden />} title="UK delivery" text="Delivery cost shown before you pay" />
                    <TrustPoint icon={<BadgePercent className="size-5" aria-hidden />} title="Pack and case prices" text="Pay less per item when you buy more" />
                    <TrustPoint icon={<ShieldCheck className="size-5" aria-hidden />} title="Secure checkout" text="Card payments processed by Stripe" />
                </ul>
            </section>

            {departments.length > 0 && (
                <section aria-labelledby="departments" className="mx-auto max-w-7xl px-4 pt-14">
                    <SectionHeading id="departments" title="Shop by department" />
                    <ul className="-mx-4 mt-6 flex gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-3 sm:gap-6 sm:overflow-visible sm:px-0 lg:grid-cols-6 [&::-webkit-scrollbar]:hidden">
                        {departments.map((d) => (
                            <li key={d.slug} className="w-24 shrink-0 sm:w-auto">
                                <Link href={storefrontLinks.category(d.slug)} className="group flex flex-col items-center text-center">
                                    <span className="relative block aspect-square w-full overflow-hidden rounded-full bg-muted/70 ring-1 ring-border/60 transition-all duration-300 group-hover:ring-2 group-hover:ring-primary/40 group-hover:shadow-lg">
                                        {d.image_url ? (
                                            <img src={d.image_url} alt="" loading="lazy" className="size-full object-contain p-[18%] mix-blend-multiply transition-transform duration-500 motion-reduce:transition-none motion-safe:group-hover:scale-110" />
                                        ) : (
                                            <span className="flex size-full items-center justify-center text-muted-foreground">
                                                <ImageOff className="size-6" aria-hidden />
                                            </span>
                                        )}
                                    </span>
                                    <span className="mt-3 text-sm font-semibold leading-snug group-hover:text-primary">{d.name}</span>
                                    <span className="mt-0.5 text-xs text-muted-foreground">
                                        {d.product_count} {d.product_count === 1 ? 'product' : 'products'}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="products" className="mx-auto max-w-7xl px-4 pt-14">
                <SectionHeading
                    id="products"
                    title="Featured and new"
                    action={
                        <Link href="/search" className="group inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold hover:text-primary">
                            See all <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" aria-hidden />
                        </Link>
                    }
                />
                {products.length > 0 ? (
                    <ul className="mt-6 grid grid-cols-2 gap-x-3 gap-y-8 sm:grid-cols-3 sm:gap-x-5 lg:grid-cols-4 xl:grid-cols-5">
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
                <section className="mx-auto max-w-7xl px-4 pt-16">
                    <div className="relative isolate flex flex-col items-start justify-between gap-6 overflow-hidden rounded-[2rem] bg-primary px-6 py-10 text-primary-foreground sm:flex-row sm:items-center sm:px-12 sm:py-12">
                        <div aria-hidden className="absolute -right-24 -top-24 -z-10 size-80 rounded-full bg-white/10 blur-2xl" />
                        <div>
                            <h2 className="text-2xl font-extrabold tracking-tight">Buying for a business?</h2>
                            <p className="mt-2 max-w-md text-sm leading-relaxed opacity-90">Open a trade account for trade prices, credit terms and fast bulk ordering.</p>
                        </div>
                        <Link
                            href="/register?type=trade"
                            className="inline-flex min-h-12 shrink-0 items-center gap-2 rounded-full bg-background px-6 text-sm font-semibold text-foreground shadow-lg transition-transform hover:bg-background/95 motion-safe:hover:-translate-y-0.5"
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
            <h2 id={id} className="text-2xl font-extrabold tracking-tight sm:text-3xl">
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
        <form role="search" onSubmit={submit} className="relative mt-8 max-w-xl">
            <label htmlFor={inputId} className="sr-only">
                Search products
            </label>
            <Search className="pointer-events-none absolute left-5 top-1/2 size-5 -translate-y-1/2 text-zinc-500" aria-hidden />
            <input
                ref={input}
                id={inputId}
                type="search"
                placeholder="Search products"
                className="h-14 w-full rounded-full border-0 bg-white pl-14 pr-32 text-base text-zinc-950 shadow-xl shadow-black/20 outline-none placeholder:text-zinc-500 focus:ring-4 focus:ring-primary/40"
            />
            <button type="submit" className="absolute right-1.5 top-1/2 h-11 -translate-y-1/2 rounded-full bg-primary px-6 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90">
                Search
            </button>
        </form>
    );
}

function TrustPoint({ icon, title, text }: { icon: ReactNode; title: string; text: string }) {
    return (
        <li className="flex items-center gap-3.5 rounded-2xl bg-muted/50 px-5 py-4">
            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-background text-primary shadow-sm ring-1 ring-border/60">{icon}</span>
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
        <section aria-labelledby="recently-viewed" className="mx-auto max-w-7xl px-4 pt-14">
            <SectionHeading id="recently-viewed" title="Recently viewed" />
            <ul className="mt-5 flex gap-3 overflow-x-auto pb-2 sm:gap-4">
                {items.map((item) => (
                    <li key={item.slug} className="w-36 shrink-0 sm:w-40">
                        <Link href={storefrontLinks.product(item)} className="group block">
                            <div className="aspect-square overflow-hidden rounded-2xl bg-muted/60">
                                {item.thumbnail_url ? (
                                    <img src={item.thumbnail_url} alt="" loading="lazy" className="size-full object-contain p-3 transition-transform duration-300 motion-reduce:transition-none motion-safe:group-hover:scale-105" />
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
