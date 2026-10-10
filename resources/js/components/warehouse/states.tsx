/**
 * Cards and page states for the warehouse screens, in the admin panel's
 * look: white cards with a hairline ring on the slate canvas, and the same
 * empty, loading and error states everywhere.
 */
import { AlertTriangle, Inbox, Loader2, RotateCw, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { TARGET } from '@/components/warehouse/scan';
import { cn } from '@/lib/utils';

/** A plain card. */
export const PANEL = 'rounded-xl bg-white shadow-sm ring-1 ring-slate-200';

/** The card the user is working in right now: a form open on a line. */
export const ACTIVE_PANEL = 'rounded-xl bg-white shadow-md ring-2 ring-blue-500';

/** A selectable chip (pack choice): chosen and not chosen. */
export const CHIP_ON = 'border-blue-600 bg-blue-600 text-white';
export const CHIP_OFF = 'border-slate-300 bg-white hover:border-slate-400';

export function Panel({ title, icon: Icon, actions, className, children, ...rest }: { title?: ReactNode; icon?: LucideIcon; actions?: ReactNode; className?: string; children: ReactNode } & React.HTMLAttributes<HTMLElement>) {
    return (
        <section className={cn(PANEL, className)} {...rest}>
            {(title || actions) && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                    {title && (
                        <h2 className="flex items-center gap-2 text-base font-semibold">
                            {Icon && <Icon className="size-5 text-slate-400" aria-hidden />}
                            {title}
                        </h2>
                    )}
                    {actions}
                </div>
            )}
            <div className="p-5">{children}</div>
        </section>
    );
}

export function EmptyState({ icon: Icon = Inbox, title, children }: { icon?: LucideIcon; title: string; children?: ReactNode }) {
    return (
        <div className={cn(PANEL, 'flex flex-col items-center px-6 py-12 text-center')} role="status">
            <span className="mb-4 flex size-14 items-center justify-center rounded-full bg-slate-100">
                <Icon className="size-7 text-slate-400" aria-hidden />
            </span>
            <p className="text-lg font-semibold">{title}</p>
            {children && <div className="mt-1 max-w-md text-base text-slate-500">{children}</div>}
        </div>
    );
}

export function LoadingState({ label }: { label: string }) {
    return (
        <div className={cn(PANEL, 'space-y-4 p-6')} role="status" aria-live="polite">
            <p className="flex items-center gap-3 text-base font-medium text-slate-600">
                <Loader2 className="size-5 animate-spin text-blue-600" aria-hidden /> {label}
            </p>
            <div className="space-y-3" aria-hidden>
                <div className="h-4 w-2/3 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-1/2 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-3/4 animate-pulse rounded bg-slate-100" />
            </div>
        </div>
    );
}

export function ErrorState({ title = 'This could not be loaded', message, onRetry, children }: { title?: string; message: string; onRetry?: () => void; children?: ReactNode }) {
    return (
        <div className="rounded-xl bg-red-50 p-6 ring-1 ring-red-200" role="alert">
            <p className="flex items-center gap-2 text-lg font-semibold text-red-900">
                <AlertTriangle className="size-6 text-red-600" aria-hidden /> {title}
            </p>
            <p className="mt-1 text-base text-red-800">{message}</p>
            <div className="mt-4 flex flex-wrap gap-2">
                {onRetry && (
                    <Button type="button" className={cn(TARGET, 'px-5')} onClick={onRetry}>
                        <RotateCw aria-hidden /> Try again
                    </Button>
                )}
                {children}
            </div>
        </div>
    );
}
