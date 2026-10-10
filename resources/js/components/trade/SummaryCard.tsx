/** 05.16 §2: a labelled figure in a summary panel; money right-aligned and tabular. */
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export function SummaryCard({ label, value, note, emphasis = false }: { label: string; value: ReactNode; note?: ReactNode; emphasis?: boolean }) {
    return (
        <div className={cn('flex flex-col gap-1 rounded-xl border bg-background p-5', emphasis && 'border-primary/40 ring-1 ring-primary/20')}>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums tracking-tight">{value}</dd>
            {note && <dd className="text-xs text-muted-foreground">{note}</dd>}
        </div>
    );
}
