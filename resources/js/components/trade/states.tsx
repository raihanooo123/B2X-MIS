/**
 * 05.16 §4: the four states every screen specifies — first-use empty
 * (with a permitted action), filtered empty (with Clear filters), a
 * skeleton matching the loaded geometry, a recoverable error (with Retry
 * and a safe reference), and success naming the result.
 */
import { CheckCircle2, Inbox, RefreshCw, SearchX, TriangleAlert, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

function Panel({ icon: Icon, tone = 'muted', title, children, action, role }: { icon: LucideIcon; tone?: 'muted' | 'danger' | 'success'; title: string; children?: ReactNode; action?: ReactNode; role?: 'alert' | 'status' }) {
    return (
        <div role={role} className={cn('flex flex-col items-center gap-3 rounded-xl border bg-background px-6 py-10 text-center', tone === 'danger' && 'border-red-200', tone === 'success' && 'border-emerald-200')}>
            <span className={cn('flex size-12 items-center justify-center rounded-full', tone === 'danger' ? 'bg-red-50 text-red-700' : tone === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-muted text-muted-foreground')}>
                <Icon className="size-6" aria-hidden />
            </span>
            <h2 className="text-base font-semibold">{title}</h2>
            {children && <div className="max-w-md text-sm text-muted-foreground">{children}</div>}
            {action && <div className="mt-1 flex flex-wrap justify-center gap-2">{action}</div>}
        </div>
    );
}

/** Nothing here yet — say why, and offer what this person may do next. */
export function EmptyState({ title, children, action, icon = Inbox }: { title: string; children?: ReactNode; action?: ReactNode; icon?: LucideIcon }) {
    return (
        <Panel icon={icon} title={title} action={action}>
            {children}
        </Panel>
    );
}

/** Filters exclude everything: the way back is one press. */
export function FilteredEmptyState({ onClear, children }: { onClear: () => void; children?: ReactNode }) {
    return (
        <Panel icon={SearchX} title="Nothing matches these filters" action={<Button variant="outline" className="h-11" onClick={onClear}>Clear filters</Button>}>
            {children ?? 'Try another search, status or date, or clear the filters.'}
        </Panel>
    );
}

/** A recoverable failure: what happened, Retry, and a reference support can trace. */
export function ErrorState({ title = 'This could not be loaded', message, reference, onRetry }: { title?: string; message?: string; reference?: string | null; onRetry?: () => void }) {
    return (
        <Panel
            icon={TriangleAlert}
            tone="danger"
            role="alert"
            title={title}
            action={onRetry && (
                <Button className="h-11" onClick={onRetry}>
                    <RefreshCw aria-hidden /> Try again
                </Button>
            )}
        >
            <p>{message ?? 'Nothing was changed. Check your connection and try again.'}</p>
            {reference && <p className="mt-2 font-mono text-xs">Reference: {reference}</p>}
        </Panel>
    );
}

/** The action worked: name the resulting record and where to go next. */
export function SuccessState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
    return (
        <Panel icon={CheckCircle2} tone="success" role="status" title={title} action={action}>
            {children}
        </Panel>
    );
}

/** Rows shaped like the table (desktop) and cards (mobile) they stand in for. */
export function TableSkeleton({ rows = 6, columns = 5, label = 'Loading' }: { rows?: number; columns?: number; label?: string }) {
    return (
        <div role="status" aria-label={label} className="overflow-hidden rounded-xl border bg-background">
            <div className="hidden lg:block">
                <div className="flex gap-4 border-b bg-muted/50 px-4 py-3">
                    {Array.from({ length: columns }, (_, i) => (
                        <Skeleton key={i} className="h-4 flex-1" />
                    ))}
                </div>
                {Array.from({ length: rows }, (_, r) => (
                    <div key={r} className="flex gap-4 border-b px-4 py-4 last:border-0">
                        {Array.from({ length: columns }, (_, i) => (
                            <Skeleton key={i} className={cn('h-4 flex-1', i === columns - 1 && 'max-w-24')} />
                        ))}
                    </div>
                ))}
            </div>
            <ul className="divide-y lg:hidden">
                {Array.from({ length: Math.min(rows, 4) }, (_, r) => (
                    <li key={r} className="space-y-3 p-4">
                        <Skeleton className="h-5 w-1/2" />
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-11 w-full" />
                    </li>
                ))}
            </ul>
            <span className="sr-only">{label}…</span>
        </div>
    );
}

/** Summary cards while loading. */
export function SummarySkeleton({ cards = 4 }: { cards?: number }) {
    return (
        <div role="status" aria-label="Loading summary" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {Array.from({ length: cards }, (_, i) => (
                <div key={i} className="space-y-3 rounded-xl border bg-background p-5">
                    <Skeleton className="h-4 w-1/2" />
                    <Skeleton className="h-7 w-2/3" />
                </div>
            ))}
        </div>
    );
}
