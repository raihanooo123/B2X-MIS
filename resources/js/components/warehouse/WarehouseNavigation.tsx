import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/shared';

const links = [
    { permission: 'goods_in', href: '/warehouse/goods-in', label: 'Goods in' },
    { permission: 'picking', href: '/warehouse/pick-list', label: 'Picking' },
    { permission: 'dispatch', href: '/warehouse/dispatch', label: 'Dispatch' },
    { permission: 'stocktake', href: '/warehouse/stocktake', label: 'Stocktake' },
] as const;

export function WarehouseNavigation() {
    const page = usePage<SharedProps>();
    const allowed = page.props.auth?.staff_navigation;
    const visible = links.filter((link) => allowed?.[link.permission]);

    if (visible.length === 0) return null;

    return (
        <nav aria-label="Warehouse" className="overflow-x-auto border-b border-slate-300 bg-white">
            <div className="mx-auto flex w-max min-w-full max-w-6xl items-center px-4 sm:w-full">
                {visible.map((link) => {
                    const active = page.url.split('?')[0] === link.href;
                    return (
                        <Link
                            key={link.href}
                            href={link.href}
                            aria-current={active ? 'page' : undefined}
                            className={`inline-flex min-h-12 shrink-0 items-center border-b-2 px-4 text-base font-medium ${active ? 'border-slate-800 text-slate-900' : 'border-transparent text-slate-600 hover:border-slate-400 hover:text-slate-900'}`}
                        >
                            {link.label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
