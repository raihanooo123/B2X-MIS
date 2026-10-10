/**
 * 05.16 §3: status always has text plus an icon; colour never carries the
 * meaning alone. Tones meet 4.5:1 text contrast on their own backgrounds.
 */
import { CheckCircle2, Circle, Clock, Info, TriangleAlert, XCircle, type LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'pending';

const TONES: Record<StatusTone, { icon: LucideIcon; className: string }> = {
    neutral: { icon: Circle, className: 'border-zinc-300 bg-zinc-100 text-zinc-800' },
    info: { icon: Info, className: 'border-sky-300 bg-sky-50 text-sky-900' },
    success: { icon: CheckCircle2, className: 'border-emerald-300 bg-emerald-50 text-emerald-900' },
    warning: { icon: TriangleAlert, className: 'border-amber-300 bg-amber-50 text-amber-950' },
    danger: { icon: XCircle, className: 'border-red-300 bg-red-50 text-red-900' },
    pending: { icon: Clock, className: 'border-violet-300 bg-violet-50 text-violet-900' },
};

export function StatusBadge({ tone, children, className }: { tone: StatusTone; children: string; className?: string }) {
    const { icon: Icon, className: toneClass } = TONES[tone];

    return (
        <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium', toneClass, className)}>
            <Icon className="size-3.5" aria-hidden />
            {children}
        </span>
    );
}
