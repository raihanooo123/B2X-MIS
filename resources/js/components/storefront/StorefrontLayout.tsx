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
import { ChevronDown, Mail, Menu, Phone, Search, ShoppingBag, User, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';

import { storefrontLinks } from '@/lib/storefront/links';
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
        <div style={brandStyle} className="flex min-h-screen flex-col bg-background text-foreground">
            <Head title={title === brand.name ? title : `${title} · ${brand.name}`}>{description && <meta name="description" content={description} />}</Head>
            <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:shadow">
                Skip to content
            </a>

            <UtilityBar brand={brand} />

            <header className="sticky top-0 z-30 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 md:gap-6">
                    <button
                        type="button"
                        className="-ml-2 inline-flex size-11 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground lg:hidden"
                        aria-label="Open the category menu"
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen(true)}
                    >
                        <Menu className="size-5" aria-hidden />
                    </button>

                    <Link href={storefrontLinks.home()} className="flex shrink-0 items-center gap-2" aria-label={`${brand.name} home`}>
                        {brand.logo_url ? (
                            <img src={brand.logo_url} alt="" className="h-9 w-auto max-w-[10rem] object-contain" />
                        ) : (
                            <span className="text-lg font-semibold tracking-tight">{brand.name}</span>
                        )}
                    </Link>

                    <SearchBox className="hidden flex-1 md:flex" />

                    <nav aria-label="Account" className="ml-auto flex items-center gap-1 text-sm">
                        <AccountLink />
                        <Link
                            href={storefrontLinks.cart()}
                            className="relative inline-flex min-h-11 items-center gap-2 rounded-md px-3 font-medium hover:bg-muted"
                            aria-label={`Basket, ${shell?.cart_count ?? 0} ${shell?.cart_count === 1 ? 'item' : 'items'}`}
                        >
                            <ShoppingBag className="size-5" aria-hidden />
                            <span className="hidden sm:inline">Basket</span>
                            {(shell?.cart_count ?? 0) > 0 && (
                                <span aria-hidden className="absolute right-0.5 top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[11px] font-semibold text-primary-foreground sm:static">
                                    {shell?.cart_count}
                                </span>
                            )}
                        </Link>
                    </nav>
                </div>

                <div className="px-4 pb-3 md:hidden">
                    <SearchBox />
                </div>

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
        <div className="bg-muted/60 text-xs text-muted-foreground">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-1.5">
                <div className="flex flex-wrap items-center gap-x-4">
                    {brand.support_phone && (
                        <a href={`tel:${brand.support_phone.replace(/[^+\d]/g, '')}`} className="inline-flex min-h-8 items-center gap-1.5 hover:text-foreground">
                            <Phone className="size-3.5" aria-hidden /> {brand.support_phone}
                        </a>
                    )}
                    {brand.support_email && (
                        <a href={`mailto:${brand.support_email}`} className="hidden min-h-8 items-center gap-1.5 hover:text-foreground sm:inline-flex">
                            <Mail className="size-3.5" aria-hidden /> {brand.support_email}
                        </a>
                    )}
                </div>
                {price_display.can_switch ? <VatSwitch mode={price_display.mode} /> : <span>Prices {price_display.mode === 'gross' ? 'include' : 'exclude'} VAT</span>}
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
        <div role="group" aria-label="Show prices" className="inline-flex items-center gap-2">
            <span className="hidden sm:inline">Prices</span>
            <div className="inline-flex rounded-md border bg-background p-0.5">
                {(['gross', 'net'] as const).map((option) => (
                    <button
                        key={option}
                        type="button"
                        aria-pressed={mode === option}
                        onClick={() => set(option)}
                        className={cn(
                            'min-h-8 rounded px-2.5 font-medium transition-colors',
                            mode === option ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {option === 'gross' ? 'Inc VAT' : 'Ex VAT'}
                    </button>
                ))}
            </div>
        </div>
    );
}

function SearchBox({ className }: { className?: string }) {
    const inputId = useId();
    const input = useRef<HTMLInputElement>(null);

    // 05.15 §3.2: "/" focuses search, as on the order pad (05.1 §8.1).
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            const typing = target?.closest('input, textarea, select, [contenteditable="true"]');
            if (e.key === '/' && !typing && input.current?.offsetParent !== null) {
                e.preventDefault();
                input.current?.focus();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const q = input.current?.value.trim() ?? '';
        if (q !== '') {
            router.visit(storefrontLinks.search(q));
        }
    };

    return (
        <form role="search" onSubmit={submit} className={cn('relative w-full max-w-2xl', className)}>
            <label htmlFor={inputId} className="sr-only">
                Search products
            </label>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <input
                ref={input}
                id={inputId}
                type="search"
                name="q"
                placeholder="Search products or SKU codes"
                autoComplete="off"
                className="h-11 w-full rounded-lg border bg-muted/40 pl-9 pr-4 text-base outline-none transition-colors placeholder:text-muted-foreground focus:border-ring focus:bg-background focus:ring-2 focus:ring-ring/30 md:h-10 md:text-sm"
            />
        </form>
    );
}

function AccountLink() {
    const { auth } = usePage<SharedProps>().props;
    const cls = 'inline-flex min-h-11 items-center gap-2 rounded-md px-3 hover:bg-muted';

    if (auth === null) {
        return (
            <>
                <Link href="/login" className={cls}>
                    <User className="size-5" aria-hidden />
                    <span className="hidden sm:inline">Sign in</span>
                </Link>
                <Link href="/register" className={cn(cls, 'hidden font-medium lg:inline-flex')}>
                    Create account
                </Link>
            </>
        );
    }

    return (
        <Link href="/account" className={cls}>
            <User className="size-5" aria-hidden />
            <span className="hidden sm:inline">{auth.company ? auth.company.name : auth.user.first_name}</span>
            <span className="sr-only sm:hidden">Your account</span>
        </Link>
    );
}

/** Desktop category bar: each department opens its sub-categories on hover or keyboard focus. */
function CategoryBar({ categories }: { categories: NavCategory[] }) {
    return (
        <nav aria-label="Categories" className="hidden border-t lg:block">
            <ul className="mx-auto flex max-w-7xl items-center gap-1 px-4">
                {categories.map((category) => (
                    <CategoryMenu key={category.slug} category={category} />
                ))}
            </ul>
        </nav>
    );
}

function CategoryMenu({ category }: { category: NavCategory }) {
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const wrapper = useRef<HTMLLIElement>(null);

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
                <Link href={storefrontLinks.category(category.slug)} className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium hover:bg-muted">
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
                className={cn('inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm font-medium hover:bg-muted', open && 'bg-muted')}
            >
                {category.name}
                <ChevronDown className={cn('size-3.5 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
            </button>
            {open && (
                <div id={menuId} className="absolute left-0 top-full z-40 w-64 rounded-lg border bg-background p-2 shadow-lg">
                    <Link href={storefrontLinks.category(category.slug)} className="block rounded-md px-3 py-2 text-sm font-semibold hover:bg-muted">
                        All {category.name}
                    </Link>
                    <ul>
                        {category.children.map((child) => (
                            <li key={child.slug}>
                                <Link href={storefrontLinks.category(child.slug)} className="block rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    {child.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </li>
    );
}

/** Small-screen category menu: a drawer from the left. */
function CategoryDrawer({ open, onClose, categories, brand }: { open: boolean; onClose: () => void; categories: NavCategory[]; brand: SharedBrand }) {
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

    return (
        <div className="fixed inset-0 z-50 bg-black/40 lg:hidden" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div ref={panel} role="dialog" aria-modal="true" aria-label="Categories" className="flex h-full w-80 max-w-[85vw] flex-col bg-background shadow-xl">
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <span className="font-semibold">{brand.name}</span>
                    <button type="button" onClick={onClose} className="inline-flex size-11 items-center justify-center rounded-md hover:bg-muted" aria-label="Close the menu">
                        <X className="size-5" aria-hidden />
                    </button>
                </div>
                <nav aria-label="Categories" className="flex-1 overflow-y-auto p-2">
                    {categories.map((category) => (
                        <details key={category.slug} className="group">
                            <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between rounded-md px-3 font-medium hover:bg-muted">
                                {category.name}
                                <ChevronDown className="size-4 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden />
                            </summary>
                            <ul className="mb-2 ml-3 border-l pl-2">
                                <li>
                                    <Link href={storefrontLinks.category(category.slug)} className="flex min-h-11 items-center rounded-md px-3 text-sm font-medium hover:bg-muted">
                                        All {category.name}
                                    </Link>
                                </li>
                                {category.children.map((child) => (
                                    <li key={child.slug}>
                                        <Link href={storefrontLinks.category(child.slug)} className="flex min-h-11 items-center rounded-md px-3 text-sm text-muted-foreground hover:bg-muted">
                                            {child.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    ))}
                </nav>
            </div>
        </div>
    );
}

function Footer({ brand, categories }: { brand: SharedBrand; categories: NavCategory[] }) {
    const { legal } = brand;
    const year = new Date().getFullYear();

    return (
        <footer className="mt-16 border-t bg-muted/40 text-sm">
            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p className="text-base font-semibold">{brand.name}</p>
                    {brand.tagline && <p className="mt-1 text-muted-foreground">{brand.tagline}</p>}
                </div>

                {categories.length > 0 && (
                    <div>
                        <h2 className="mb-3 font-semibold">Shop</h2>
                        <ul className="space-y-2 text-muted-foreground">
                            {categories.slice(0, 8).map((c) => (
                                <li key={c.slug}>
                                    <Link href={storefrontLinks.category(c.slug)} className="hover:text-foreground">
                                        {c.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div>
                    <h2 className="mb-3 font-semibold">Help</h2>
                    <ul className="space-y-2 text-muted-foreground">
                        {brand.support_phone && (
                            <li>
                                <a href={`tel:${brand.support_phone.replace(/[^+\d]/g, '')}`} className="hover:text-foreground">
                                    {brand.support_phone}
                                </a>
                            </li>
                        )}
                        {brand.support_email && (
                            <li>
                                <a href={`mailto:${brand.support_email}`} className="hover:text-foreground">
                                    {brand.support_email}
                                </a>
                            </li>
                        )}
                        <li>
                            <Link href="/account" className="hover:text-foreground">
                                Your account
                            </Link>
                        </li>
                        <li>
                            <Link href="/register?type=trade" className="hover:text-foreground">
                                Apply for a trade account
                            </Link>
                        </li>
                    </ul>
                </div>

                {(legal.name || legal.address.length > 0) && (
                    <div>
                        <h2 className="mb-3 font-semibold">Company</h2>
                        <address className="space-y-0.5 not-italic text-muted-foreground">
                            {legal.name && <p>{legal.name}</p>}
                            {legal.address.map((line) => (
                                <p key={line}>{line}</p>
                            ))}
                            {legal.company_number && <p className="pt-2">Company no. {legal.company_number}</p>}
                            {legal.vat_number && <p>VAT no. {legal.vat_number}</p>}
                        </address>
                    </div>
                )}
            </div>

            <div className="border-t">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-4 text-xs text-muted-foreground">
                    <p>
                        © {year} {legal.name ?? brand.name}
                    </p>
                    {brand.show_powered_by && (
                        <p>
                            Powered by <span className="font-semibold text-foreground">B2X MIS</span> · by Raihan
                        </p>
                    )}
                </div>
            </div>
        </footer>
    );
}
