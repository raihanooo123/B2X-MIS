/**
 * The frame for every "your account" page: the site bar, a side navigation
 * between the account sections (a row of tabs on small screens), the page
 * heading with optional actions, and the flashed status message.
 */
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

interface AccountLayoutProps {
    title: string;
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

export function AccountLayout({ title, eyebrow, description, actions, status, children }: AccountLayoutProps) {
    const { auth } = usePage<SharedProps>().props;
    const path = usePage().url.split('?')[0];

    const items: NavItem[] = [
        { href: '/account', label: 'Account & security', icon: ShieldCheck, show: true },
        { href: '/account/team', label: 'Team', icon: Users, show: auth?.can_manage_team ?? false },
    ];

    return (
        <>
            <Head title={title} />
            <div className="min-h-screen bg-muted/30">
                <div className="border-b bg-background">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3">
                        <Link href="/order-pad" className="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground md:min-h-0">
                            <ArrowLeft className="size-4" aria-hidden /> Back to ordering
                        </Link>
                        <AccountMenu />
                    </div>
                </div>

                <div className="mx-auto grid max-w-6xl gap-6 px-4 py-6 md:grid-cols-[13rem_1fr] md:py-8">
                    <nav aria-label="Account sections" className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                        <p className="mb-2 hidden px-3 text-xs font-medium uppercase tracking-wide text-muted-foreground md:block">Your account</p>
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
                                                    'flex min-h-11 items-center gap-2.5 whitespace-nowrap rounded-md px-3 text-sm md:min-h-9',
                                                    active ? 'bg-background font-medium text-foreground shadow-sm ring-1 ring-border' : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
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

                    <main className="min-w-0">
                        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                            <div className="min-w-0">
                                {eyebrow && <p className="mb-1 text-sm text-muted-foreground">{eyebrow}</p>}
                                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                                {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
                            </div>
                            {actions}
                        </div>

                        {status && (
                            <p role="status" className="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">
                                {status}
                            </p>
                        )}

                        {children}
                    </main>
                </div>
            </div>
        </>
    );
}

/** A white section card on the account pages. */
export function AccountCard({ title, description, children, className }: { title?: string; description?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('rounded-xl border bg-background shadow-sm', className)}>
            {title && (
                <header className="border-b px-5 py-4">
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
                </header>
            )}
            {children}
        </section>
    );
}
