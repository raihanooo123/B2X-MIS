/**
 * The storefront header's account control: "Hello, sign in" for guests, and
 * for a signed-in user a panel with their account sections and Sign out —
 * the same links the order pad's AccountMenu offers, by the same rules.
 *
 * A disclosure (button + panel of links), not an ARIA menu: every entry is
 * an ordinary link, reached with Tab. Escape, an outside click or any
 * navigation closes it.
 */
import { Link, router, usePage } from '@inertiajs/react';
import { Building2, ChevronDown, ClipboardList, LayoutDashboard, LogOut, MailWarning, MapPin, Receipt, ShieldCheck, ShoppingBag, User, Users, type LucideIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import { cn } from '@/lib/utils';
import type { SharedAuth, SharedProps } from '@/types/shared';

interface Entry {
    href: string;
    label: string;
    icon: LucideIcon;
    external?: boolean;
    tone?: 'warning';
}

/** The account links a signed-in user has, in display order. Shared with the mobile drawer. */
export function accountEntries(auth: SharedAuth): Entry[] {
    const entries: Entry[] = [];
    if (auth.public_customer) {
        entries.push(
            { href: '/account/orders', label: 'My orders', icon: ShoppingBag },
            { href: '/account/receipts', label: 'My receipts', icon: Receipt },
            { href: '/account/addresses', label: 'Delivery addresses', icon: MapPin },
        );
    }
    if (auth.company) entries.push({ href: '/order-pad', label: 'Order pad', icon: ClipboardList });
    if (auth.can_manage_team) entries.push({ href: '/account/team', label: 'Team', icon: Users });
    if (auth.staff_navigation.admin) entries.push({ href: '/admin', label: 'Admin', icon: LayoutDashboard, external: true });
    if (!auth.user.email_verified) entries.push({ href: '/email/verify', label: 'Confirm your email', icon: MailWarning, tone: 'warning' });
    entries.push({ href: '/account', label: 'Account & security', icon: ShieldCheck });
    return entries;
}

export function AccountDropdown() {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const wrapper = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };
        const onDown = (e: MouseEvent) => wrapper.current && !wrapper.current.contains(e.target as Node) && setOpen(false);
        const offNavigate = router.on('start', () => setOpen(false));
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onDown);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onDown);
            offNavigate();
        };
    }, [open]);

    const greeting = auth ? `Hello, ${auth.user.first_name}` : 'Hello, sign in';

    return (
        <div
            ref={wrapper}
            className="relative"
            onBlur={(e) => open && !wrapper.current?.contains(e.relatedTarget as Node) && setOpen(false)}
        >
            <button
                ref={trigger}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((v) => !v)}
                className={cn(
                    'inline-flex min-h-11 items-center gap-2 rounded-xl px-2.5 text-left transition-colors hover:bg-muted sm:px-3',
                    open && 'bg-muted',
                )}
            >
                <User className="size-5 shrink-0" aria-hidden />
                <span className="hidden leading-tight sm:block">
                    <span className="block max-w-[9rem] truncate text-xs text-muted-foreground">{greeting}</span>
                    <span className="block text-sm font-semibold">{auth?.company ? auth.company.name : 'Account'}</span>
                </span>
                <span className="sr-only sm:hidden">{auth ? 'Your account' : 'Sign in or create an account'}</span>
                <ChevronDown className={cn('hidden size-4 text-muted-foreground transition-transform sm:block', open && 'rotate-180')} aria-hidden />
            </button>

            {open && (
                <div
                    id={panelId}
                    className="absolute right-0 top-full z-50 mt-2 w-[min(20rem,calc(100vw-2rem))] origin-top-right overflow-hidden rounded-2xl border bg-background shadow-xl ring-1 ring-black/5 animate-in fade-in-0 zoom-in-95"
                >
                    {auth === null ? <GuestPanel /> : <MemberPanel auth={auth} />}
                </div>
            )}
        </div>
    );
}

function GuestPanel() {
    return (
        <div className="p-4">
            <Link href="/login" className="flex min-h-11 w-full items-center justify-center rounded-xl bg-primary text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90">
                Sign in
            </Link>
            <p className="mt-3 text-center text-sm text-muted-foreground">
                New here?{' '}
                <Link href="/register?type=public" className="font-medium text-foreground underline underline-offset-4">
                    Create an account
                </Link>
            </p>
            <div className="mt-4 border-t pt-3">
                <Link href="/register?type=trade" className="flex min-h-11 items-center gap-3 rounded-lg px-2 text-sm hover:bg-muted">
                    <Building2 className="size-4 text-muted-foreground" aria-hidden />
                    <span>
                        <span className="block font-medium">Buying for a business?</span>
                        <span className="block text-xs text-muted-foreground">Apply for a trade account</span>
                    </span>
                </Link>
                <Link href="/orders/lookup" className="flex min-h-11 items-center gap-3 rounded-lg px-2 text-sm hover:bg-muted">
                    <ShoppingBag className="size-4 text-muted-foreground" aria-hidden />
                    <span className="font-medium">Find a guest order</span>
                </Link>
            </div>
        </div>
    );
}

function MemberPanel({ auth }: { auth: SharedAuth }) {
    return (
        <>
            <div className="flex items-center gap-3 border-b bg-muted/40 px-4 py-4">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground" aria-hidden>
                    {auth.user.first_name.slice(0, 1).toUpperCase()}
                </span>
                <div className="min-w-0">
                    <p className="truncate font-semibold">
                        {auth.user.first_name} {auth.user.last_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">{auth.user.email}</p>
                </div>
            </div>
            {auth.company && (
                <div className="flex items-center justify-between gap-2 border-b px-4 py-2.5 text-sm">
                    <span className="flex min-w-0 items-center gap-2">
                        <Building2 className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        <span className="sr-only">Ordering for </span>
                        <span className="truncate font-medium">{auth.company.name}</span>
                    </span>
                    {auth.can_switch_company && (
                        <Link href="/choose-company" className="inline-flex min-h-11 shrink-0 items-center text-xs font-medium text-primary hover:underline md:min-h-8">
                            Switch
                        </Link>
                    )}
                </div>
            )}
            <ul className="p-2">
                {accountEntries(auth).map((entry) => {
                    const cls = cn('flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium hover:bg-muted', entry.tone === 'warning' && 'text-amber-800');
                    const body = (
                        <>
                            <entry.icon className={cn('size-4', entry.tone === 'warning' ? 'text-amber-700' : 'text-muted-foreground')} aria-hidden />
                            {entry.label}
                        </>
                    );
                    return (
                        <li key={entry.href}>
                            {entry.external ? (
                                <a href={entry.href} className={cls}>
                                    {body}
                                </a>
                            ) : (
                                <Link href={entry.href} className={cls}>
                                    {body}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
            <div className="border-t p-2">
                <Link href="/logout" method="post" as="button" className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground">
                    <LogOut className="size-4" aria-hidden /> Sign out
                </Link>
            </div>
        </>
    );
}
