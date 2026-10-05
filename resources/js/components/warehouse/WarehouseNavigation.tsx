import { Link, usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types/shared';

const links = [
    { permission: 'goods_in', href: '/warehouse/goods-in', label: 'Goods in' },
    { permission: 'picking', href: '/warehouse/pick-list', label: 'Picking' },
    { permission: 'dispatch', href: '/warehouse/dispatch', label: 'Dispatch' },
    { permission: 'collections', href: '/warehouse/collections', label: 'Collections' },
    { permission: 'stocktake', href: '/warehouse/stocktake', label: 'Stocktake' },
    { permission: 'returns', href: '/warehouse/returns', label: 'Returns' },
] as const;

export function WarehouseNavigation() {
    const page = usePage<SharedProps>();
    const allowed = page.props.auth?.staff_navigation;
    const visible = links.filter((link) => allowed?.[link.permission]);

    if (visible.length === 0) return null;

    return (
        <nav aria-label="Warehouse" className="overflow-x-auto border-b border-border bg-background shadow-sm">
            <div className="mx-auto flex w-max min-w-full max-w-5xl items-center gap-2 px-4 py-2 sm:w-full sm:min-w-0">
                {visible.map((link) => {
                    const active = page.url.split('?')[0] === link.href;
                    return (
                        <Link
                            key={link.href}
                            href={link.href}
                            aria-current={active ? 'page' : undefined}
                            className={`inline-flex min-h-12 shrink-0 items-center rounded-xl px-4 text-sm font-semibold transition-colors ${active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                        >
                            {link.label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
