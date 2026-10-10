/**
 * The frame every warehouse and counter screen shares, matched to the
 * admin panel: a white top bar with the business name, the warehouse
 * tabs, a page header, and content on a slate canvas in the panel's blue.
 * Pages pass their title and icon; everything inside is theirs.
 */
import { Head, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { WarehouseNavigation } from '@/components/warehouse/WarehouseNavigation';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

export function WarehouseShell({
    title,
    icon: Icon,
    subtitle,
    actions,
    wide = false,
    children,
}: {
    title: string;
    icon: LucideIcon;
    subtitle?: ReactNode;
    actions?: ReactNode;
    /** Wider content for screens with tables (goods in). */
    wide?: boolean;
    children: ReactNode;
}) {
    const { brand } = usePage<SharedProps>().props;
    const width = wide ? 'max-w-6xl' : 'max-w-5xl';

    return (
        <div className="staff-theme min-h-screen bg-slate-50 pb-24 text-lg text-slate-900 antialiased">
            <Head title={title} />
            <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div className={cn('mx-auto flex h-16 items-center justify-between gap-3 px-4 sm:px-6', width)}>
                    <a href="/admin" className="flex min-h-12 min-w-0 items-center gap-2 rounded-lg text-base font-semibold tracking-tight text-slate-900">
                        <span className="truncate">{brand.name}</span>
                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium uppercase tracking-wider text-slate-500">Warehouse</span>
                    </a>
                    <AccountMenu />
                </div>
            </header>
            <WarehouseNavigation width={width} />
            <main className={cn('mx-auto space-y-6 px-4 pt-6 sm:px-6', width)}>
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                            <Icon className="size-6" aria-hidden />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                            {subtitle && <p className="text-sm text-slate-500">{subtitle}</p>}
                        </div>
                    </div>
                    {actions}
                </div>
                {children}
            </main>
        </div>
    );
}
