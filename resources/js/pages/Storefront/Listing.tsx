/**
 * Category and search results (05.15 §5.1–5.2): breadcrumb, sub-category
 * chips, filters (brand, in stock only) and sort in the query string, and
 * the product grid with "Show more" — keyset pages, appended in place
 * (02 §9 rule 8: never OFFSET, so no numbered pages).
 */
import { Link, router, usePage } from '@inertiajs/react';
import { PackageSearch, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

import { Breadcrumb } from '@/components/storefront/Breadcrumb';
import { ProductCard, type ProductCardData } from '@/components/storefront/ProductCard';
import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { storefrontLinks } from '@/lib/storefront/links';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface Filters {
    q: string | null;
    brand: string | null;
    in_stock: boolean;
    sort: 'name' | 'newest';
}

interface ListingProps {
    shell: ShellProps;
    category: {
        name: string;
        slug: string;
        meta_title: string | null;
        meta_description: string | null;
        breadcrumb: { name: string; slug: string }[];
        children: { name: string; slug: string }[];
    } | null;
    filters: Filters;
    products: ProductCardData[];
    next_cursor: string | null;
    is_continuation: boolean;
    brands: { slug: string; name: string }[];
}

export default function Listing({ shell, category, filters, products, next_cursor, is_continuation, brands }: ListingProps) {
    const { price_display } = usePage<SharedProps>().props;
    const [items, setItems] = useState(products);
    const [loadingMore, setLoadingMore] = useState(false);
    const [filtersOpen, setFiltersOpen] = useState(false);
    // Opened from a "Show more" URL (a refresh, a shared link): earlier
    // products are not on the page, so offer the start of the list.
    const [openedMidList] = useState(is_continuation);

    // A "Show more" page appends; anything else (new filters) replaces.
    useEffect(() => {
        setItems((current) => (is_continuation ? [...current, ...products.filter((p) => !current.some((c) => c.id === p.id))] : products));
    }, [products, is_continuation]);

    const path = category ? storefrontLinks.category(category.slug) : '/search';
    const title = category ? category.name : filters.q ? `Results for “${filters.q}”` : 'All products';

    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        router.get(path, query(next), { preserveScroll: true, preserveState: true, only: ['products', 'next_cursor', 'is_continuation', 'filters', 'brands'] });
    };

    const showMore = () => {
        if (next_cursor === null) return;
        setLoadingMore(true);
        router.get(path, { ...query(filters), after: next_cursor }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['products', 'next_cursor', 'is_continuation'],
            onFinish: () => setLoadingMore(false),
        });
    };

    const activeFilters = (filters.brand ? 1 : 0) + (filters.in_stock ? 1 : 0);

    return (
        <StorefrontLayout title={category?.meta_title ?? title} description={category?.meta_description ?? undefined} shell={shell}>
            <div className="border-b border-border/60">
                <div className="mx-auto max-w-7xl px-4 pb-5 pt-3 sm:pb-8 sm:pt-6">
                    <Breadcrumb
                        trail={(category?.breadcrumb ?? []).slice(0, -1).map((c) => ({ name: c.name, href: storefrontLinks.category(c.slug) }))}
                        current={category ? category.name : 'Search'}
                    />
                    <h1 className="mt-1 text-[1.75rem] font-extrabold leading-tight tracking-tight sm:mt-3 sm:text-4xl">{title}</h1>
                    {category && category.children.length > 0 && (
                        <ul className="-mx-4 mt-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0 [&::-webkit-scrollbar]:hidden" aria-label={`Ranges in ${category.name}`}>
                            {category.children.map((child) => (
                                <li key={child.slug} className="shrink-0">
                                    <Link
                                        href={storefrontLinks.category(child.slug)}
                                        className="inline-flex min-h-11 items-center rounded-full border bg-muted/40 px-4 text-sm font-medium transition-colors hover:border-foreground/30 hover:bg-background"
                                    >
                                        {child.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>

            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-6 lg:grid-cols-[15rem_1fr]">
                <aside aria-label="Filters" className={cn('lg:block', filtersOpen ? 'block' : 'hidden')}>
                    <FilterPanel filters={filters} brands={brands} onChange={visit} />
                </aside>

                <section aria-labelledby="results" className="min-w-0">
                    <h2 id="results" className="sr-only">
                        Products
                    </h2>
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <button
                            type="button"
                            className="inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-semibold lg:hidden"
                            aria-expanded={filtersOpen}
                            onClick={() => setFiltersOpen((v) => !v)}
                        >
                            <SlidersHorizontal className="size-4" aria-hidden /> Filters{activeFilters > 0 && ` (${activeFilters})`}
                        </button>
                        <p className="text-sm text-muted-foreground">
                            {items.length === 0 ? 'No products' : `Showing ${items.length}${next_cursor ? '+' : ''} ${items.length === 1 ? 'product' : 'products'}`}
                        </p>
                        <SortSelect value={filters.sort} onChange={(sort) => visit({ sort })} />
                    </div>

                    {openedMidList && (
                        <p className="mb-4 text-sm">
                            <Link href={path} data={query(filters)} className="font-medium text-primary hover:underline">
                                Back to the start of the list
                            </Link>
                        </p>
                    )}

                    {items.length === 0 ? (
                        <div className="flex flex-col items-center rounded-xl border border-dashed px-6 py-16 text-center">
                            <PackageSearch className="mb-3 size-8 text-muted-foreground" aria-hidden />
                            <p className="font-medium">No products match{filters.q ? ` “${filters.q}”` : ''}</p>
                            <p className="mt-1 text-sm text-muted-foreground">Try a shorter search, another department or fewer filters.</p>
                            {activeFilters > 0 && (
                                <button type="button" onClick={() => visit({ brand: null, in_stock: false })} className="mt-4 inline-flex min-h-11 items-center gap-1 rounded-lg border px-4 text-sm font-medium">
                                    <X className="size-4" aria-hidden /> Clear filters
                                </button>
                            )}
                        </div>
                    ) : (
                        <ul className="grid grid-cols-2 gap-x-3 gap-y-8 sm:grid-cols-3 sm:gap-x-5 xl:grid-cols-4">
                            {items.map((card) => (
                                <ProductCard key={card.id} card={card} mode={price_display.mode} />
                            ))}
                        </ul>
                    )}

                    {next_cursor !== null && (
                        <div className="mt-8 flex justify-center">
                            <button
                                type="button"
                                onClick={showMore}
                                disabled={loadingMore}
                                className="inline-flex min-h-12 items-center rounded-full border bg-background px-8 text-sm font-semibold shadow-sm transition-colors hover:bg-muted disabled:opacity-60"
                            >
                                {loadingMore ? 'Loading…' : 'Show more products'}
                            </button>
                        </div>
                    )}
                </section>
            </div>
        </StorefrontLayout>
    );
}

function query(f: Filters): Record<string, string> {
    const q: Record<string, string> = {};
    if (f.q) q.q = f.q;
    if (f.brand) q.brand = f.brand;
    if (f.in_stock) q.in_stock = '1';
    if (f.sort !== 'name') q.sort = f.sort;
    return q;
}

function SortSelect({ value, onChange }: { value: Filters['sort']; onChange: (sort: Filters['sort']) => void }) {
    const id = useId();

    return (
        <div className="flex items-center gap-2 text-sm">
            <label htmlFor={id} className="text-muted-foreground">
                Sort by
            </label>
            <select id={id} value={value} onChange={(e) => onChange(e.target.value as Filters['sort'])} className="h-11 rounded-full border bg-background px-4 font-medium md:h-9">
                <option value="name">Name, A–Z</option>
                <option value="newest">Newest</option>
            </select>
        </div>
    );
}

function FilterPanel({ filters, brands, onChange }: { filters: Filters; brands: { slug: string; name: string }[]; onChange: (c: Partial<Filters>) => void }) {
    return (
        <div className="space-y-6 text-sm">
            <fieldset>
                <legend className="mb-2 font-semibold">Availability</legend>
                <label className="flex min-h-10 cursor-pointer items-center gap-2.5">
                    <input type="checkbox" checked={filters.in_stock} onChange={(e) => onChange({ in_stock: e.target.checked })} className="size-4 accent-primary" />
                    In stock only
                </label>
            </fieldset>

            {brands.length > 0 && (
                <fieldset>
                    <legend className="mb-2 font-semibold">Brand</legend>
                    <ul className="space-y-0.5">
                        <li>
                            <label className="flex min-h-10 cursor-pointer items-center gap-2.5">
                                <input type="radio" name="brand" checked={filters.brand === null} onChange={() => onChange({ brand: null })} className="size-4 accent-primary" />
                                All brands
                            </label>
                        </li>
                        {brands.map((brand) => (
                            <li key={brand.slug}>
                                <label className="flex min-h-10 cursor-pointer items-center gap-2.5">
                                    <input type="radio" name="brand" checked={filters.brand === brand.slug} onChange={() => onChange({ brand: brand.slug })} className="size-4 accent-primary" />
                                    {brand.name}
                                </label>
                            </li>
                        ))}
                    </ul>
                </fieldset>
            )}
        </div>
    );
}
