/**
 * The frame for every "your account" page: the site bar, a side navigation
 * between the account sections (a row of tabs on small screens), the page
 * heading with optional actions, and the flashed status message.
 *
 * A trade user acting for a company sees these pages inside the 05.16
 * trade shell instead: its sidebar already links Account and security and
 * Users and limits, so there is no second menu here.
 */
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck, Users, ShoppingBag, Receipt, MapPin, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { AccountMenu } from '@/components/auth/AccountMenu';
import { PageHeader } from '@/components/trade/PageHeader';
import { TradeShell } from '@/components/trade/TradeShell';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface AccountLayoutProps {
    title: string;
    shell?: ShellProps | null;
    eyebrow?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    status?: string | null;
    children: ReactNode;
}

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
    show: boolean;
}

export function AccountLayout({ title, eyebrow, description, actions, status, children, shell }: AccountLayoutProps) {
    const { auth } = usePage<SharedProps>().props;
    const path = usePage().url.split('?')[0];

    if (auth?.trade_navigation != null) {
        return (
            <TradeShell title={title}>
                <PageHeader
                    breadcrumbs={[{ label: 'Dashboard', href: '/trade' }, { label: title }]}
                    title={title}
                    description={description}
                    primaryAction={actions}
                />
                {status && (
                    <p role="status" className="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">
                        {status}
                    </p>
                )}
                <div className="flex max-w-4xl flex-col gap-6">{children}</div>
            </TradeShell>
        );
    }

    const items: NavItem[] = [
        { href: '/account/orders', label: 'My orders', icon: ShoppingBag, show: auth?.public_customer ?? false },
        { href: '/account/receipts', label: 'My receipts', icon: Receipt, show: auth?.public_customer ?? false },
        { href: '/account/addresses', label: 'Delivery addresses', icon: MapPin, show: auth?.public_customer ?? false },
        { href: '/account', label: 'Account & security', icon: ShieldCheck, show: true },
        { href: '/account/team', label: 'Team', icon: Users, show: auth?.can_manage_team ?? false },
    ];

    const content = (
        <>
            {!auth?.public_customer && <Head title={title} />}
            <div className="min-h-[70vh] bg-gradient-to-b from-primary/5 via-muted/30 to-background">
                {!auth?.public_customer && <div className="border-b bg-background">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3">
                        <Link href="/order-pad" className="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground md:min-h-0">
                            <ArrowLeft className="size-4" aria-hidden /> Back to ordering
                        </Link>
                        <AccountMenu />
                    </div>
                </div>}

                <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 md:grid-cols-[15rem_1fr] md:gap-8 md:py-8">
                    <nav aria-label="Account sections" className="overflow-x-auto rounded-2xl border bg-background p-3 shadow-sm md:sticky md:top-36 md:self-start md:p-4">
                        <div className="mb-4 hidden border-b border-border/60 px-3 pb-5 pt-2 md:block">
                            <div className="mb-3 flex size-10 items-center justify-center rounded-xl bg-primary text-base font-semibold text-primary-foreground shadow-sm">{auth?.user.first_name.slice(0, 1).toUpperCase()}</div>
                            <p className="text-lg font-semibold tracking-tight">Hello, {auth?.user.first_name}</p>
                            <p className="mt-1 text-xs text-muted-foreground">Your account</p>
                        </div>
                        <ul className="flex gap-1 md:flex-col">
                            {items
                                .filter((item) => item.show)
                                .map((item) => {
                                    const active = path === item.href;
                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                aria-current={active ? 'page' : undefined}
                                                className={cn(
                                                    'flex min-h-11 items-center gap-2.5 whitespace-nowrap rounded-xl px-3 text-sm',
                                                    active ? 'bg-primary font-semibold text-primary-foreground shadow-sm' : 'text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                                                )}
                                            >
                                                <item.icon className="size-4" aria-hidden />
                                                {item.label}
                                            </Link>
                                        </li>
                                    );
                                })}
                        </ul>
                    </nav>

                    <section aria-label={title} className="min-w-0">
                        <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b border-border/60 pb-6">
                            <div className="min-w-0">
                                {eyebrow && <p className="mb-1 text-sm text-muted-foreground">{eyebrow}</p>}
                                <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">{title}</h1>
                                {description && <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{description}</p>}
                            </div>
                            {actions}
                        </div>

                        {status && (
                            <p role="status" className="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">
                                {status}
                            </p>
                        )}

                        {children}
                    </section>
                </div>
            </div>
        </>
    );
    return auth?.public_customer ? <StorefrontLayout title={title} shell={shell ?? null}>{content}</StorefrontLayout> : content;
}

/** A white section card on the account pages. */
export function AccountCard({ title, description, children, className }: { title?: string; description?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('overflow-hidden rounded-2xl border bg-background shadow-sm', className)}>
            {title && (
                <header className="border-b bg-muted/20 px-6 py-5">
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
                </header>
            )}
            {children}
        </section>
    );
}
