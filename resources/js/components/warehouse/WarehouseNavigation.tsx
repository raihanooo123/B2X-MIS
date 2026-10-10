import { Link, usePage } from '@inertiajs/react';
import { ClipboardCheck, ClipboardList, PackagePlus, Store, Truck, Undo2 } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/shared';

const links = [
    { permission: 'goods_in', href: '/warehouse/goods-in', label: 'Goods in', icon: PackagePlus },
    { permission: 'picking', href: '/warehouse/pick-list', label: 'Picking', icon: ClipboardList },
    { permission: 'dispatch', href: '/warehouse/dispatch', label: 'Dispatch', icon: Truck },
    { permission: 'collections', href: '/warehouse/collections', label: 'Collections', icon: Store },
    { permission: 'stocktake', href: '/warehouse/stocktake', label: 'Stocktake', icon: ClipboardCheck },
    { permission: 'returns', href: '/warehouse/returns', label: 'Returns', icon: Undo2 },
] as const;

/** The warehouse tabs, styled as the admin panel's active menu item. */
export function WarehouseNavigation({ width = 'max-w-5xl' }: { width?: string }) {
    const page = usePage<SharedProps>();
    const allowed = page.props.auth?.staff_navigation;
    const visible = links.filter((link) => allowed?.[link.permission]);

    if (visible.length === 0) return null;

    return (
        <nav aria-label="Warehouse" className="overflow-x-auto border-b border-slate-200 bg-white">
            <div className={cn('mx-auto flex w-max min-w-full items-center gap-1 px-4 py-2 sm:w-full sm:min-w-0 sm:px-6', width)}>
                {visible.map((link) => {
                    const active = page.url.split('?')[0] === link.href;
                    const Icon = link.icon;
                    return (
                        <Link
                            key={link.href}
                            href={link.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'inline-flex min-h-12 shrink-0 items-center gap-2 rounded-lg px-4 text-base font-medium transition-colors',
                                active ? 'bg-blue-50 font-semibold text-blue-700 ring-1 ring-blue-100' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                            )}
                        >
                            <Icon className={cn('size-5', active ? 'text-blue-600' : 'text-slate-400')} aria-hidden />
                            {link.label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
