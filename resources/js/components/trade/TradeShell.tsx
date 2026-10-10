/**
 * 05.16 §2 — the trade app shell every B2B screen shares: a skip link; a
 * top bar with the active company (and switch), search, help and the
 * profile menu; a 240 px sidebar from 1280 px, a labelled drawer below;
 * one <main> landmark with the 16/24/32 px gutters of each breakpoint and
 * a 1440 px content limit; and the toast live region.
 *
 * Navigation is policy-gated by the server (`auth.trade_navigation`):
 * Dashboard and Orders for every member; Invoices, Credit notes and
 * Statements (05.17), Approvals, Credit, Balance and Users only for those
 * allowed. Quotes and Returns join as their modules ship (ROADMAP), so
 * the menu never links to a page that does not exist.
 *
 * White-label, as the storefront: the brand colour overrides `--primary`.
 */
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, ChevronDown, CircleHelp, ClipboardCheck, FileMinus, FileText, LayoutDashboard, Landmark, LogOut, Menu, Package, ScrollText, Search, ShieldCheck, ShoppingCart, User, Users, Wallet, type LucideIcon } from 'lucide-react';
import { useState, type CSSProperties, type ReactNode } from 'react';

import { Toaster } from '@/components/trade/Toaster';
import { clearSavedViews } from '@/components/trade/useSavedViews';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
    /** A count shown beside the label, with its meaning spelled out for screen readers. */
    badge?: { count: number; label: string };
}

interface NavGroup {
    label: string | null;
    items: NavItem[];
}

function navigation(nav: NonNullable<NonNullable<SharedProps['auth']>['trade_navigation']> | null): NavGroup[] {
    const ordering: NavItem[] = [];
    if (nav?.orders) {
        ordering.push({ href: '/trade', label: 'Dashboard', icon: LayoutDashboard });
    }
    ordering.push({ href: '/order-pad', label: 'Order pad', icon: ShoppingCart });
    if (nav?.orders) {
        ordering.push({ href: '/trade/orders', label: 'Orders', icon: Package });
    }
    if (nav?.approvals) {
        ordering.push({
            href: '/trade/approvals',
            label: 'Approvals',
            icon: ClipboardCheck,
            badge: nav.pending_approvals > 0 ? { count: nav.pending_approvals, label: `${nav.pending_approvals} waiting` } : undefined,
        });
    }

    const documents: NavItem[] = nav?.finance
        ? [
              { href: '/trade/invoices', label: 'Invoices', icon: FileText },
              { href: '/trade/credit-notes', label: 'Credit notes', icon: FileMinus },
              { href: '/trade/statements', label: 'Statements', icon: ScrollText },
          ]
        : [];

    const account: NavItem[] = [];
    if (nav?.credit) {
        account.push({ href: '/trade/account/credit', label: 'Credit', icon: Landmark }, { href: '/trade/account/balance', label: 'Account balance', icon: Wallet });
    }
    if (nav?.users) {
        account.push({ href: '/trade/account/users', label: 'Users and limits', icon: Users });
    }
    account.push({ href: '/account', label: 'Account and security', icon: ShieldCheck });

    return [
        { label: null, items: ordering },
        ...(documents.length > 0 ? [{ label: 'Documents', items: documents }] : []),
        { label: 'Account', items: account },
    ];
}

function SidebarNav({ groups, path, onNavigate }: { groups: NavGroup[]; path: string; onNavigate?: () => void }) {
    return (
        <nav aria-label="Main" className="flex flex-col gap-6 p-4">
            {groups.map((group, i) => (
                <div key={group.label ?? i}>
                    {group.label && <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</p>}
                    <ul className="flex flex-col gap-1">
                        {group.items.map((item) => {
                            const active = path === item.href || (item.href !== '/trade' && path.startsWith(`${item.href}/`));

                            return (
                                <li key={item.href}>
                                    <Link
                                        href={item.href}
                                        onClick={onNavigate}
                                        aria-current={active ? 'page' : undefined}
                                        className={cn(
                                            'flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            active ? 'bg-primary font-semibold text-primary-foreground' : 'text-foreground/80 hover:bg-muted hover:text-foreground',
                                        )}
                                    >
                                        <item.icon className="size-4 shrink-0" aria-hidden />
                                        <span className="flex-1">{item.label}</span>
                                        {item.badge && (
                                            <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums', active ? 'bg-primary-foreground text-primary' : 'bg-amber-100 text-amber-900')}>
                                                <span aria-hidden>{item.badge.count}</span>
                                                <span className="sr-only">{item.badge.label}</span>
                                            </span>
                                        )}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

interface TradeShellProps {
    /** The document title (one h1 per page comes from PageHeader). */
    title: string;
    children: ReactNode;
}

export function TradeShell({ title, children }: TradeShellProps) {
    const { auth, brand } = usePage<SharedProps>().props;
    const path = usePage().url.split('?')[0];
    const [drawerOpen, setDrawerOpen] = useState(false);
    const groups = navigation(auth?.trade_navigation ?? null);

    const brandStyle = (brand.primary_hsl ? { '--primary': brand.primary_hsl, '--ring': brand.primary_hsl, '--primary-foreground': brand.primary_foreground_hsl ?? '0 0% 100%' } : {}) as CSSProperties;

    const signOut = () => {
        clearSavedViews();
        router.post('/logout');
    };

    return (
        <div style={brandStyle} className="min-h-screen bg-muted/40 text-foreground antialiased">
            <Head title={`${title} · ${brand.name}`} />
            <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-3 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-3 focus:shadow-lg focus:ring-2 focus:ring-ring">
                Skip to content
            </a>

            <header className="sticky top-0 z-30 flex h-16 items-center gap-2 border-b bg-background px-4 md:px-6 min-[1440px]:px-8">
                <button
                    type="button"
                    onClick={() => setDrawerOpen(true)}
                    aria-label="Open menu"
                    aria-expanded={drawerOpen}
                    className="-ml-2 inline-flex size-11 items-center justify-center rounded-lg hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring xl:hidden"
                >
                    <Menu className="size-5" aria-hidden />
                </button>
                <Link href="/order-pad" className="flex min-h-11 shrink-0 items-center gap-2 rounded-lg px-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    {brand.logo_url ? <img src={brand.logo_url} alt={brand.name} className="h-8 w-auto max-w-[9rem] object-contain" /> : <span className="text-base font-bold tracking-tight">{brand.name}</span>}
                </Link>

                {auth?.company && (
                    <div className="ml-2 hidden min-w-0 items-center gap-2 border-l pl-4 md:flex">
                        <Building2 className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        <p className="truncate text-sm">
                            <span className="sr-only">Acting for </span>
                            <span className="font-medium">{auth.company.name}</span>
                        </p>
                        {auth.can_switch_company && (
                            <Link href="/choose-company" className="inline-flex min-h-11 items-center px-1 text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                Switch company
                            </Link>
                        )}
                    </div>
                )}

                <div className="ml-auto flex items-center gap-1">
                    <Link href="/search" className="inline-flex size-11 items-center justify-center rounded-lg hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label="Search the catalogue">
                        <Search className="size-5" aria-hidden />
                    </Link>
                    <Link href="/contact" className="inline-flex size-11 items-center justify-center rounded-lg hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label="Help and contact">
                        <CircleHelp className="size-5" aria-hidden />
                    </Link>
                    {auth && (
                        <DropdownMenu>
                            <DropdownMenuTrigger className="inline-flex min-h-11 items-center gap-2 rounded-lg px-2 text-sm hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                <span className="flex size-8 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground" aria-hidden>
                                    {auth.user.first_name.slice(0, 1).toUpperCase()}
                                </span>
                                <span className="hidden max-w-[10rem] truncate sm:inline">{auth.user.first_name}</span>
                                <ChevronDown className="size-4 text-muted-foreground" aria-hidden />
                                <span className="sr-only">Account menu</span>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-64">
                                <DropdownMenuLabel className="font-normal">
                                    <p className="font-medium">{`${auth.user.first_name} ${auth.user.last_name}`}</p>
                                    <p className="truncate text-xs text-muted-foreground">{auth.user.email}</p>
                                    {auth.company && <p className="mt-1 truncate text-xs text-muted-foreground md:hidden">Acting for {auth.company.name}</p>}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {auth.company && auth.can_switch_company && (
                                    <DropdownMenuItem asChild className="min-h-11 md:hidden">
                                        <Link href="/choose-company">
                                            <Building2 aria-hidden /> Switch company
                                        </Link>
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem asChild className="min-h-11">
                                    <Link href="/account">
                                        <User aria-hidden /> Account and security
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem className="min-h-11" onSelect={signOut}>
                                    <LogOut aria-hidden /> Sign out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </header>

            <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
                <SheetContent side="left" className="w-[18rem] p-0 sm:max-w-[18rem]">
                    <SheetHeader className="border-b px-4 py-4 text-left">
                        <SheetTitle>Menu</SheetTitle>
                        <SheetDescription className="truncate">{auth?.company?.name ?? brand.name}</SheetDescription>
                    </SheetHeader>
                    <SidebarNav groups={groups} path={path} onNavigate={() => setDrawerOpen(false)} />
                </SheetContent>
            </Sheet>

            <div className="xl:grid xl:min-h-[calc(100vh-4rem)] xl:grid-cols-[240px_minmax(0,1fr)]">
                <aside className="hidden border-r bg-background xl:block">
                    <div className="sticky top-16 max-h-[calc(100vh-4rem)] overflow-y-auto">
                        <SidebarNav groups={groups} path={path} />
                    </div>
                </aside>
                <main id="main" tabIndex={-1} className="mx-auto w-full min-w-0 max-w-[1440px] px-4 py-6 focus:outline-none md:px-6 md:py-8 min-[1440px]:px-8">
                    {children}
                </main>
            </div>

            <Toaster />
        </div>
    );
}
