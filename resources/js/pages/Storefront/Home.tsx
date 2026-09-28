/**
 * Storefront home (05.15 §5.1): the brand's hero with search, the
 * departments, and featured then newest products. Promotional content
 * arrives with the CMS (05.11).
 */
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, BadgePercent, Search, ShieldCheck, Truck } from 'lucide-react';
import { useId, useRef, type FormEvent } from 'react';

import { ProductCard, type ProductCardData } from '@/components/storefront/ProductCard';
import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { storefrontLinks } from '@/lib/storefront/links';
import type { SharedProps } from '@/types/shared';

interface HomeProps {
    shell: ShellProps;
    products: ProductCardData[];
}

export default function Home({ shell, products }: HomeProps) {
    const { brand, price_display, auth } = usePage<SharedProps>().props;

    return (
        <StorefrontLayout title={brand.name} description={brand.tagline ?? `Shop online with ${brand.name}.`} shell={shell}>
            <Hero title={brand.name} tagline={brand.tagline} />

            <section aria-label="Why shop with us" className="border-b">
                <ul className="mx-auto grid max-w-7xl gap-4 px-4 py-5 text-sm sm:grid-cols-3">
                    <Promise icon={<Truck className="size-5" aria-hidden />} title="UK delivery" text="Delivered to your door, with the cost shown before you pay." />
                    <Promise icon={<BadgePercent className="size-5" aria-hidden />} title="Pack and case prices" text="The more you buy, the less you pay per item." />
                    <Promise icon={<ShieldCheck className="size-5" aria-hidden />} title="Secure checkout" text="Card payments processed securely by Stripe." />
                </ul>
            </section>

            {shell.categories.length > 0 && (
                <section aria-labelledby="departments" className="mx-auto max-w-7xl px-4 pt-10">
                    <h2 id="departments" className="text-xl font-semibold tracking-tight">
                        Shop by department
                    </h2>
                    <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        {shell.categories.map((category) => (
                            <li key={category.slug}>
                                <Link
                                    href={storefrontLinks.category(category.slug)}
                                    className="group flex h-full min-h-24 flex-col justify-between rounded-xl border bg-card p-4 transition-colors hover:border-primary/40 hover:bg-primary/5"
                                >
                                    <span className="font-medium leading-snug">{category.name}</span>
                                    <span className="mt-3 inline-flex items-center gap-1 text-xs text-muted-foreground group-hover:text-primary">
                                        {category.children.length > 0 ? `${category.children.length} ranges` : 'Browse'}
                                        <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" aria-hidden />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="products" className="mx-auto max-w-7xl px-4 pt-12">
                <div className="flex items-end justify-between gap-4">
                    <h2 id="products" className="text-xl font-semibold tracking-tight">
                        Featured and new
                    </h2>
                    <Link href="/order-pad" className="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary hover:underline">
                        See all products <ArrowRight className="size-4" aria-hidden />
                    </Link>
                </div>
                {products.length > 0 ? (
                    <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-6">
                        {products.map((card) => (
                            <ProductCard key={card.id} card={card} mode={price_display.mode} />
                        ))}
                    </ul>
                ) : (
                    <p className="mt-4 rounded-xl border border-dashed px-6 py-12 text-center text-muted-foreground">New products are on their way. Please check back soon.</p>
                )}
            </section>

            {auth?.company == null && (
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

function Hero({ title, tagline }: { title: string; tagline: string | null }) {
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
        <section className="relative overflow-hidden border-b bg-gradient-to-br from-primary/10 via-background to-background">
            <div className="mx-auto max-w-7xl px-4 py-12 sm:py-16 lg:py-20">
                <h1 className="max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl lg:text-5xl">{title}</h1>
                <p className="mt-3 max-w-xl text-base text-muted-foreground sm:text-lg">{tagline ?? 'Everyday essentials at wholesale value, delivered across the UK.'}</p>
                <form role="search" onSubmit={submit} className="mt-6 flex max-w-xl gap-2">
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
                    <button type="submit" className="h-12 shrink-0 rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90">
                        Search
                    </button>
                </form>
            </div>
        </section>
    );
}

function Promise({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) {
    return (
        <li className="flex items-start gap-3">
            <span className="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">{icon}</span>
            <span>
                <span className="block font-medium">{title}</span>
                <span className="block text-muted-foreground">{text}</span>
            </span>
        </li>
    );
}
