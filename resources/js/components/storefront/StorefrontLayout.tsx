/**
 * The public storefront frame (05.15 §3.2): header with the business's own
 * logo, search, category menu, account and cart; the Inc/Ex VAT switch for
 * guests and public customers; a footer with the legal details a UK company
 * must show on its website, and the product credit.
 *
 * White-label: every name, colour and contact detail comes from the shared
 * `brand` prop (App\Domain\Storefront\Branding). The brand colour overrides
 * the `--primary` token for everything inside the layout.
 */
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, ChevronDown, ChevronRight, LogOut, Mail, Menu, Phone, Search, ShoppingBag, User, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';

import { AccountDropdown, accountEntries } from '@/components/storefront/AccountDropdown';
import { storefrontLinks } from '@/lib/storefront/links';
import { shelfPrice } from '@/lib/storefront/price';
import { cn } from '@/lib/utils';
import type { SharedBrand, SharedProps } from '@/types/shared';

export interface NavCategory {
    slug: string;
    name: string;
    children: { slug: string; name: string }[];
}

export interface ShellProps {
    categories: NavCategory[];
    cart_count: number;
}

interface StorefrontLayoutProps {
    title: string;
    description?: string;
    shell: ShellProps | null;
    children: ReactNode;
}

export function StorefrontLayout({ title, description, shell, children }: StorefrontLayoutProps) {
    const { brand, flash } = usePage<SharedProps>().props;
    const categories = shell?.categories ?? [];
    const [menuOpen, setMenuOpen] = useState(false);

    const brandStyle = (
        brand.primary_hsl ? { '--primary': brand.primary_hsl, '--ring': brand.primary_hsl, '--primary-foreground': brand.primary_foreground_hsl ?? '0 0% 100%' } : {}
    ) as CSSProperties;

    return (
        <div style={brandStyle} className="storefront flex min-h-screen flex-col bg-background text-foreground antialiased">
            <Head title={title === brand.name ? title : `${title} · ${brand.name}`}>{description && <meta name="description" content={description} />}</Head>
            <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:shadow">
                Skip to content
            </a>

            <UtilityBar brand={brand} />

            <header className="sticky top-0 z-30 border-b border-border/60 bg-background/90 backdrop-blur-xl supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 py-2.5 sm:gap-3 md:gap-6 md:py-3">
                    <button
                        type="button"
                        className="-ml-2 inline-flex size-11 items-center justify-center rounded-xl text-foreground hover:bg-muted lg:hidden"
                        aria-label="Open the menu"
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen(true)}
                    >
                        <Menu className="size-5" aria-hidden />
                    </button>

                    <Link href={storefrontLinks.home()} className="flex min-h-11 shrink-0 items-center gap-2" aria-label={`${brand.name} home`}>
                        {brand.logo_url ? (
                            <img src={brand.logo_url} alt="" className="h-9 w-auto max-w-[10rem] object-contain" />
                        ) : (
                            <BrandMark name={brand.name} />
                        )}
                    </Link>

                    <SearchBox className="hidden flex-1 md:flex" />

                    <nav aria-label="Account" className="ml-auto flex items-center gap-0.5 sm:gap-1">
                        <AccountDropdown />
                        <Link
                            href={storefrontLinks.cart()}
                            className="relative inline-flex min-h-11 items-center gap-2 rounded-xl px-2.5 transition-colors hover:bg-muted sm:px-3"
                            aria-label={`Basket, ${shell?.cart_count ?? 0} ${shell?.cart_count === 1 ? 'item' : 'items'}`}
                        >
                            <span className="relative">
                                <ShoppingBag className="size-5" aria-hidden />
                                {(shell?.cart_count ?? 0) > 0 && (
                                    <span aria-hidden className="absolute -right-2.5 -top-2 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[11px] font-bold leading-none text-primary-foreground ring-2 ring-background">
                                        {shell?.cart_count}
                                    </span>
                                )}
                            </span>
                            <span className="hidden text-sm font-semibold sm:inline">Basket</span>
                        </Link>
                    </nav>
                </div>

                <div className="px-4 pb-2.5 md:hidden">
                    <SearchBox />
                </div>

                {categories.length > 0 && <CategoryStrip categories={categories} />}
                {categories.length > 0 && <CategoryBar categories={categories} />}
            </header>

            <CategoryDrawer open={menuOpen} onClose={() => setMenuOpen(false)} categories={categories} brand={brand} />

            <main id="main" className="flex-1">
                {flash.status && (
                    <div className="mx-auto max-w-7xl px-4 pt-4">
                        <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">
                            {flash.status}
                        </p>
                    </div>
                )}
                {children}
            </main>

            <Footer brand={brand} categories={categories} />
        </div>
    );
}

function UtilityBar({ brand }: { brand: SharedBrand }) {
    const { price_display } = usePage<SharedProps>().props;

    return (
        <div className="bg-zinc-950 text-xs text-zinc-300">
            <div className="mx-auto flex max-w-7xl items-center justify-between gap-x-4 px-4">
                <div className="flex min-w-0 items-center gap-x-5">
                    {brand.support_phone && (
                        <a href={`tel:${brand.support_phone.replace(/[^+\d]/g, '')}`} className="inline-flex min-h-11 items-center gap-1.5 hover:text-white md:min-h-9">
                            <Phone className="size-3.5" aria-hidden /> {brand.support_phone}
                        </a>
                    )}
                    {brand.support_email && (
                        <a href={`mailto:${brand.support_email}`} className="hidden min-h-9 items-center gap-1.5 hover:text-white sm:inline-flex">
                            <Mail className="size-3.5" aria-hidden /> {brand.support_email}
                        </a>
                    )}
                </div>
                <p className="hidden text-zinc-400 lg:block">Trade and retail · UK delivery · Secure checkout by Stripe</p>
                {price_display.can_switch ? <VatSwitch mode={price_display.mode} /> : <span className="py-2.5">Prices {price_display.mode === 'gross' ? 'include' : 'exclude'} VAT</span>}
            </div>
        </div>
    );
}

/** 05.15 §4.1: Inc VAT / Ex VAT, remembered in a cookie by the server. */
function VatSwitch({ mode }: { mode: 'net' | 'gross' }) {
    const set = (next: 'net' | 'gross') => {
        if (next !== mode) {
            router.post('/price-display', { mode: next }, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <div role="group" aria-label="Show prices" className="inline-flex shrink-0 items-center gap-2">
            <span className="hidden text-zinc-400 sm:inline">Prices</span>
            <div className="inline-flex rounded-full bg-white/10 p-0.5">
                {(['gross', 'net'] as const).map((option) => (
                    <button
                        key={option}
                        type="button"
                        aria-pressed={mode === option}
                        onClick={() => set(option)}
                        className={cn(
                            'min-h-11 rounded-full px-3.5 font-semibold transition-colors md:min-h-7 md:px-3',
                            mode === option ? 'bg-white text-zinc-950 shadow-sm' : 'text-zinc-300 hover:text-white',
                        )}
                    >
                        {option === 'gross' ? 'Inc VAT' : 'Ex VAT'}
                    </button>
                ))}
            </div>
        </div>
    );
}

/** The text wordmark when the business has no logo: a monogram tile and the name. */
function BrandMark({ name }: { name: string }) {
    return (
        <span className="flex items-center gap-2.5">
            <span className="flex size-9 items-center justify-center rounded-xl bg-primary text-base font-extrabold text-primary-foreground shadow-sm" aria-hidden>
                {name.slice(0, 1).toUpperCase()}
            </span>
            <span className="text-lg font-extrabold tracking-tight">{name}</span>
        </span>
    );
}

interface Suggestion {
    name: string;
    slug: string;
    thumbnail_url: string | null;
    price: { unit_net_e4: number; tax_rate_bp: number; varies: boolean } | null;
}

/**
 * Header search with suggestions as the buyer types (05.15 §5.3a): an ARIA
 * combobox. ↑/↓ move through the list, Enter opens the highlighted product
 * or runs the search, Escape closes. "/" focuses it, as on the order pad.
 */
function SearchBox({ className }: { className?: string }) {
    const { price_display } = usePage<SharedProps>().props;
    const inputId = useId();
    const listId = useId();
    const input = useRef<HTMLInputElement>(null);
    const wrapper = useRef<HTMLFormElement>(null);
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Suggestion[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            const typing = target?.closest('input, textarea, select, [contenteditable="true"]');
            if (e.key === '/' && !typing && input.current?.offsetParent !== null) {
                e.preventDefault();
                input.current?.focus();
            }
        };
        const onDown = (e: MouseEvent) => wrapper.current && !wrapper.current.contains(e.target as Node) && setOpen(false);
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onDown);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onDown);
        };
    }, []);

    // Debounced, and a stale answer never replaces a newer one.
    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setResults([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/search/suggest?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .then((body: { data: Suggestion[] }) => {
                    setResults(body.data);
                    setActive(-1);
                    setOpen(true);
                })
                .catch(() => undefined);
        }, 200);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [q]);

    const go = (url: string) => {
        setOpen(false);
        router.visit(url);
    };

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (open && active >= 0 && results[active]) {
            go(storefrontLinks.product(results[active]));
            return;
        }
        const term = q.trim();
        if (term !== '') {
            go(storefrontLinks.search(term));
        }
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown' && results.length > 0) {
            e.preventDefault();
            setOpen(true);
            setActive((i) => (i + 1) % results.length);
        } else if (e.key === 'ArrowUp' && results.length > 0) {
            e.preventDefault();
            setActive((i) => (i <= 0 ? results.length - 1 : i - 1));
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    const showList = open && q.trim().length >= 2;

    return (
        <form ref={wrapper} role="search" onSubmit={submit} className={cn('relative w-full max-w-2xl', className)}>
            <label htmlFor={inputId} className="sr-only">
                Search products
            </label>
            <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <input
                ref={input}
                id={inputId}
                type="search"
                name="q"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onFocus={() => results.length > 0 && setOpen(true)}
                onKeyDown={onKeyDown}
                role="combobox"
                aria-expanded={showList}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
                placeholder="Search products or SKU codes"
                autoComplete="off"
                className="h-12 w-full rounded-full border border-transparent bg-muted/70 pl-11 pr-14 md:h-11 text-base outline-none transition-colors placeholder:text-muted-foreground hover:bg-muted focus:border-ring focus:bg-background focus:ring-4 focus:ring-ring/15 md:text-sm"
            />
            <button
                type="submit"
                aria-label="Search"
                className="absolute right-0.5 top-1/2 inline-flex size-11 -translate-y-1/2 md:right-1 md:size-9 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
            >
                <ArrowRight className="size-4" aria-hidden />
            </button>
            {showList && (
                <div className="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border bg-background shadow-xl ring-1 ring-black/5">
                    {results.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">No matching products. Press Enter to search anyway.</p>
                    ) : (
                        <ul id={listId} role="listbox" aria-label="Suggestions" className="max-h-96 overflow-y-auto py-1">
                            {results.map((item, i) => (
                                <li
                                    key={item.slug}
                                    id={`${listId}-${i}`}
                                    role="option"
                                    aria-selected={i === active}
                                    onMouseEnter={() => setActive(i)}
                                    onMouseDown={(e) => {
                                        e.preventDefault();
                                        go(storefrontLinks.product(item));
                                    }}
                                    className={cn('flex cursor-pointer items-center gap-3 px-3 py-2 text-sm', i === active && 'bg-muted')}
                                >
                                    <span className="size-10 shrink-0 overflow-hidden rounded-md bg-muted/50">
                                        {item.thumbnail_url && <img src={item.thumbnail_url} alt="" className="size-full object-cover" />}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate font-medium">{item.name}</span>
                                    {item.price && (
                                        <span className="shrink-0 tabular-nums text-muted-foreground">
                                            {item.price.varies ? 'from ' : ''}
                                            {shelfPrice(item.price.unit_net_e4, item.price.tax_rate_bp, price_display.mode)}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    <button
                        type="submit"
                        onMouseDown={() => setActive(-1)}
                        className="flex w-full items-center gap-2 border-t px-4 py-2.5 text-left text-sm font-medium text-primary hover:bg-muted"
                    >
                        <Search className="size-4" aria-hidden /> See all results for “{q.trim()}”
                    </button>
                </div>
            )}
        </form>
    );
}

/** Phones and tablets: departments as a swipeable row of chips under the search, as marketplace apps do. */
function CategoryStrip({ categories }: { categories: NavCategory[] }) {
    const path = usePage().url.split('?')[0];

    return (
        <nav aria-label="Departments" className="lg:hidden">
            <ul className="flex gap-2 overflow-x-auto px-4 pb-2.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {categories.map((category) => {
                    const href = storefrontLinks.category(category.slug);
                    const active = path === href || category.children.some((c) => path === storefrontLinks.category(c.slug));
                    return (
                        <li key={category.slug} className="shrink-0">
                            <Link
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-medium transition-colors',
                                    active ? 'border-foreground bg-foreground text-background' : 'border-border bg-background hover:border-foreground/30',
                                )}
                            >
                                {category.name}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/** Desktop category bar: each department opens its sub-categories on hover or keyboard focus. */
function CategoryBar({ categories }: { categories: NavCategory[] }) {
    return (
        <nav aria-label="Categories" className="hidden border-t border-border/60 lg:block">
            <ul className="mx-auto flex max-w-7xl items-center gap-1 px-4">
                {categories.map((category) => (
                    <CategoryMenu key={category.slug} category={category} />
                ))}
            </ul>
        </nav>
    );
}

const barItem =
    'relative inline-flex min-h-11 items-center gap-1 px-3 text-sm font-medium text-foreground/80 transition-colors hover:text-foreground after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:origin-left after:scale-x-0 after:rounded-full after:bg-primary after:transition-transform hover:after:scale-x-100';

function CategoryMenu({ category }: { category: NavCategory }) {
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const wrapper = useRef<HTMLLIElement>(null);
    const path = usePage().url.split('?')[0];
    const href = storefrontLinks.category(category.slug);
    const current = path === href || category.children.some((c) => path === storefrontLinks.category(c.slug));

    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open]);

    if (category.children.length === 0) {
        return (
            <li>
                <Link href={href} aria-current={current ? 'page' : undefined} className={cn(barItem, current && 'text-foreground after:scale-x-100')}>
                    {category.name}
                </Link>
            </li>
        );
    }

    return (
        <li
            ref={wrapper}
            className="relative"
            onMouseEnter={() => setOpen(true)}
            onMouseLeave={() => setOpen(false)}
            onBlur={(e) => !wrapper.current?.contains(e.relatedTarget as Node) && setOpen(false)}
        >
            <button
                type="button"
                aria-expanded={open}
                aria-controls={menuId}
                onClick={() => setOpen((v) => !v)}
                className={cn(barItem, (open || current) && 'text-foreground after:scale-x-100')}
            >
                {category.name}
                <ChevronDown className={cn('size-3.5 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
            </button>
            {open && (
                <div id={menuId} className="absolute left-0 top-full z-40 w-72 pt-1">
                    <div className="overflow-hidden rounded-2xl border bg-background shadow-xl ring-1 ring-black/5 animate-in fade-in-0 slide-in-from-top-1">
                        <ul className="p-2">
                            {category.children.map((child) => (
                                <li key={child.slug}>
                                    <Link href={storefrontLinks.category(child.slug)} className="group flex min-h-10 items-center justify-between rounded-lg px-3 text-sm text-foreground/80 hover:bg-muted hover:text-foreground">
                                        {child.name}
                                        <ChevronRight className="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" aria-hidden />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Link href={href} className="flex min-h-11 items-center justify-between border-t bg-muted/40 px-5 text-sm font-semibold hover:bg-muted">
                            Shop all {category.name}
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                </div>
            )}
        </li>
    );
}

/** Small-screen menu: a drawer from the left with the account, departments and help. */
function CategoryDrawer({ open, onClose, categories, brand }: { open: boolean; onClose: () => void; categories: NavCategory[]; brand: SharedBrand }) {
    const { auth } = usePage<SharedProps>().props;
    const panel = useRef<HTMLDivElement>(null);
    const close = useRef(onClose);
    close.current = onClose;

    useEffect(() => {
        if (!open) {
            return;
        }
        const previous = document.activeElement as HTMLElement | null;
        panel.current?.querySelector<HTMLElement>('button, a')?.focus();
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close.current();
        document.addEventListener('keydown', onKey);
        const offNavigate = router.on('start', () => close.current());
        return () => {
            document.removeEventListener('keydown', onKey);
            offNavigate();
            previous?.focus();
        };
    }, [open]);

    if (!open) {
        return null;
    }

    const row = 'flex min-h-12 items-center gap-3 rounded-xl px-3 text-[15px] font-medium hover:bg-muted';

    return (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm animate-in fade-in-0 lg:hidden" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div ref={panel} role="dialog" aria-modal="true" aria-label="Menu" className="flex h-full w-[22rem] max-w-[88vw] flex-col bg-background shadow-2xl animate-in slide-in-from-left">
                <div className="flex items-center justify-between gap-3 bg-zinc-950 px-4 py-3 text-white">
                    {auth ? (
                        <Link href="/account" className="flex min-h-11 min-w-0 items-center gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-white/15 text-sm font-semibold" aria-hidden>
                                {auth.user.first_name.slice(0, 1).toUpperCase()}
                            </span>
                            <span className="truncate font-semibold">Hello, {auth.user.first_name}</span>
                        </Link>
                    ) : (
                        <Link href="/login" className="flex min-h-11 items-center gap-3 font-semibold">
                            <User className="size-5" aria-hidden /> Hello, sign in
                        </Link>
                    )}
                    <button type="button" onClick={onClose} className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-white/10" aria-label="Close the menu">
                        <X className="size-5" aria-hidden />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto">
                    <nav aria-label="Departments" className="p-2">
                        <p className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Shop by department</p>
                        {categories.map((category) =>
                            category.children.length === 0 ? (
                                <Link key={category.slug} href={storefrontLinks.category(category.slug)} className={row}>
                                    {category.name}
                                </Link>
                            ) : (
                                <details key={category.slug} className="group">
                                    <summary className={cn(row, 'cursor-pointer list-none justify-between [&::-webkit-details-marker]:hidden')}>
                                        {category.name}
                                        <ChevronDown className="size-4 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden />
                                    </summary>
                                    <ul className="mb-2 ml-4 border-l pl-2">
                                        <li>
                                            <Link href={storefrontLinks.category(category.slug)} className="flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold hover:bg-muted">
                                                Shop all {category.name}
                                            </Link>
                                        </li>
                                        {category.children.map((child) => (
                                            <li key={child.slug}>
                                                <Link href={storefrontLinks.category(child.slug)} className="flex min-h-11 items-center rounded-lg px-3 text-sm text-foreground/80 hover:bg-muted">
                                                    {child.name}
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            ),
                        )}
                    </nav>

                    <div className="border-t p-2">
                        <p className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Your account</p>
                        {auth ? (
                            <>
                                {accountEntries(auth).map((entry) =>
                                    entry.external ? (
                                        <a key={entry.href} href={entry.href} className={row}>
                                            <entry.icon className="size-5 text-muted-foreground" aria-hidden /> {entry.label}
                                        </a>
                                    ) : (
                                        <Link key={entry.href} href={entry.href} className={cn(row, entry.tone === 'warning' && 'text-amber-800')}>
                                            <entry.icon className="size-5 text-muted-foreground" aria-hidden /> {entry.label}
                                        </Link>
                                    ),
                                )}
                                <Link href="/logout" method="post" as="button" className={cn(row, 'w-full text-left')}>
                                    <LogOut className="size-5 text-muted-foreground" aria-hidden /> Sign out
                                </Link>
                            </>
                        ) : (
                            <>
                                <Link href="/login" className={row}>
                                    <User className="size-5 text-muted-foreground" aria-hidden /> Sign in
                                </Link>
                                <Link href="/register?type=public" className={row}>
                                    Create an account
                                </Link>
                                <Link href="/register?type=trade" className={row}>
                                    Apply for a trade account
                                </Link>
                            </>
                        )}
                    </div>

                    {(brand.support_phone || brand.support_email) && (
                        <div className="border-t p-2 pb-6">
                            <p className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Help</p>
                            {brand.support_phone && (
                                <a href={`tel:${brand.support_phone.replace(/[^+\d]/g, '')}`} className={row}>
                                    <Phone className="size-5 text-muted-foreground" aria-hidden /> {brand.support_phone}
                                </a>
                            )}
                            {brand.support_email && (
                                <a href={`mailto:${brand.support_email}`} className={row}>
                                    <Mail className="size-5 text-muted-foreground" aria-hidden /> <span className="truncate">{brand.support_email}</span>
                                </a>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function Footer({ brand, categories }: { brand: SharedBrand; categories: NavCategory[] }) {
    const { legal } = brand;
    const year = new Date().getFullYear();
    const link = 'inline-flex min-h-11 items-center transition-colors hover:text-white md:min-h-0';

    return (
        <footer className="mt-20 bg-zinc-950 text-sm text-zinc-400">
            <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
                <div>
                    <p className="text-lg font-extrabold tracking-tight text-white">{brand.name}</p>
                    {brand.tagline && <p className="mt-2 max-w-xs leading-relaxed">{brand.tagline}</p>}
                </div>

                {categories.length > 0 && (
                    <div>
                        <h2 className="mb-4 text-xs font-semibold uppercase tracking-wider text-white">Shop</h2>
                        <ul className="md:space-y-2.5">
                            {categories.slice(0, 8).map((c) => (
                                <li key={c.slug}>
                                    <Link href={storefrontLinks.category(c.slug)} className={link}>
                                        {c.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div>
                    <h2 className="mb-4 text-xs font-semibold uppercase tracking-wider text-white">Help</h2>
                    <ul className="md:space-y-2.5">
                        {brand.support_phone && (
                            <li>
                                <a href={`tel:${brand.support_phone.replace(/[^+\d]/g, '')}`} className={link}>
                                    {brand.support_phone}
                                </a>
                            </li>
                        )}
                        {brand.support_email && (
                            <li>
                                <a href={`mailto:${brand.support_email}`} className={link}>
                                    {brand.support_email}
                                </a>
                            </li>
                        )}
                        <li>
                            <Link href="/account" className={link}>
                                Your account
                            </Link>
                        </li>
                        <li>
                            <Link href="/orders/lookup" className={link}>
                                Find a guest order
                            </Link>
                        </li>
                        <li>
                            <Link href="/register?type=trade" className={link}>
                                Apply for a trade account
                            </Link>
                        </li>
                    </ul>
                </div>

                {(legal.name || legal.address.length > 0) && (
                    <div>
                        <h2 className="mb-4 text-xs font-semibold uppercase tracking-wider text-white">Company</h2>
                        <address className="space-y-1 not-italic leading-relaxed">
                            {legal.name && <p className="text-zinc-300">{legal.name}</p>}
                            {legal.address.map((line) => (
                                <p key={line}>{line}</p>
                            ))}
                            {legal.company_number && <p className="pt-3">Company no. {legal.company_number}</p>}
                            {legal.vat_number && <p>VAT no. {legal.vat_number}</p>}
                        </address>
                    </div>
                )}
            </div>

            <div className="border-t border-white/10">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-5 text-xs">
                    <p>
                        © {year} {legal.name ?? brand.name}. All rights reserved.
                    </p>
                    {brand.show_powered_by && (
                        <p>
                            Powered by <span className="font-semibold text-white">B2X MIS</span> · by Raihan
                        </p>
                    )}
                </div>
            </div>
        </footer>
    );
}
