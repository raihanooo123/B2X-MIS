/**
 * Storefront breadcrumb. Wide screens get the full trail; phones get one
 * "back to the parent" line, as large marketplaces do, so the trail never
 * wraps into a ragged second row above the page title.
 */
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export interface Crumb {
    name: string;
    href: string;
}

export function Breadcrumb({ trail, current }: { trail: Crumb[]; current: string }) {
    const parent = trail[trail.length - 1] ?? { name: 'Home', href: '/' };

    return (
        <nav aria-label="Breadcrumb">
            <Link href={parent.href} className="-ml-1 inline-flex min-h-11 max-w-full items-center gap-1 pr-2 text-sm font-medium text-muted-foreground hover:text-foreground md:hidden">
                <ChevronLeft className="size-4 shrink-0" aria-hidden />
                <span className="truncate">{parent.name}</span>
            </Link>
            <ol className="hidden flex-wrap items-center gap-1.5 text-sm text-muted-foreground md:flex">
                {[{ name: 'Home', href: '/' }, ...trail].map((crumb) => (
                    <li key={crumb.href} className="flex items-center gap-1.5">
                        <Link href={crumb.href} className="rounded transition-colors hover:text-foreground">
                            {crumb.name}
                        </Link>
                        <ChevronRight className="size-3.5 text-muted-foreground/60" aria-hidden />
                    </li>
                ))}
                <li>
                    <span aria-current="page" className="line-clamp-1 font-medium text-foreground">
                        {current}
                    </span>
                </li>
            </ol>
        </nav>
    );
}
