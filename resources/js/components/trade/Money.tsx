/**
 * 05.16 §5: money is right-aligned with tabular figures, formatted from
 * integer pence (CLAUDE.md invariant 1) — never parsed back into a number.
 * `e4` shows a per-unit price to up to four decimals.
 */
import { formatE4, formatMinor } from '@/lib/money';
import { cn } from '@/lib/utils';

export function Money({ minor, e4, className }: { minor?: number; e4?: number; className?: string }) {
    const text = e4 !== undefined ? formatE4(e4) : formatMinor(minor ?? 0);

    return <span className={cn('whitespace-nowrap tabular-nums', className)}>{text}</span>;
}
