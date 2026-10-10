/**
 * 05.16 §2: every trade page's header — breadcrumb, the page's one h1,
 * an optional short explanation, status and reference beside the title
 * on detail pages, one primary action, and secondary actions in a menu.
 * Actions wrap under the title at narrow widths.
 */
import { Link } from '@inertiajs/react';
import { ChevronRight, MoreHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

export interface Crumb {
    label: string;
    href?: string;
}

export interface SecondaryAction {
    label: string;
    href?: string;
    onSelect?: () => void;
}

interface PageHeaderProps {
    breadcrumbs: Crumb[];
    title: string;
    description?: ReactNode;
    /** Detail pages: the status badge and reference shown beside the title. */
    status?: ReactNode;
    reference?: string;
    primaryAction?: ReactNode;
    secondaryActions?: SecondaryAction[];
}

export function PageHeader({ breadcrumbs, title, description, status, reference, primaryAction, secondaryActions = [] }: PageHeaderProps) {
    return (
        <header className="mb-6 flex flex-col gap-4 md:mb-8">
            <nav aria-label="Breadcrumb">
                <ol className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
                    {breadcrumbs.map((crumb, i) => {
                        const last = i === breadcrumbs.length - 1;

                        return (
                            <li key={`${crumb.label}-${i}`} className="flex items-center gap-1">
                                {crumb.href && !last ? (
                                    <Link href={crumb.href} className="inline-flex min-h-11 items-center rounded px-0.5 underline-offset-4 hover:text-foreground hover:underline md:min-h-0">
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span aria-current={last ? 'page' : undefined} className={last ? 'text-foreground' : undefined}>
                                        {crumb.label}
                                    </span>
                                )}
                                {!last && <ChevronRight className="size-3.5" aria-hidden />}
                            </li>
                        );
                    })}
                </ol>
            </nav>

            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                <div className="min-w-0 max-w-3xl">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 className="break-words text-2xl font-semibold tracking-tight md:text-3xl">{title}</h1>
                        {status}
                        {reference && <span className="font-mono text-sm text-muted-foreground">{reference}</span>}
                    </div>
                    {description && <div className="mt-2 text-sm leading-relaxed text-muted-foreground">{description}</div>}
                </div>

                {(primaryAction || secondaryActions.length > 0) && (
                    <div className="flex flex-wrap items-center gap-2">
                        {secondaryActions.length > 0 && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" className="h-11 min-w-11 px-3" aria-label="More actions">
                                        <MoreHorizontal aria-hidden />
                                        <span>More</span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {secondaryActions.map((action) =>
                                        action.href ? (
                                            <DropdownMenuItem key={action.label} asChild className="min-h-11">
                                                <Link href={action.href}>{action.label}</Link>
                                            </DropdownMenuItem>
                                        ) : (
                                            <DropdownMenuItem key={action.label} className="min-h-11" onSelect={action.onSelect}>
                                                {action.label}
                                            </DropdownMenuItem>
                                        ),
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                        {primaryAction}
                    </div>
                )}
            </div>
        </header>
    );
}
