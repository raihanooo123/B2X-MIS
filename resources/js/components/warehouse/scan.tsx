/**
 * Shared warehouse-screen primitives (05.5 §9): the scan bar, the notice
 * box, and scan-everywhere focus. Used by goods-in, picking and dispatch.
 *
 * Warehouse conditions, not desk conditions: 48 px targets, no hover-only
 * interaction, identifiers in a monospaced face (0/O and 1/I confusion
 * breaks a recall trace).
 */
import { CheckCircle2, CornerDownLeft, Info, ScanLine, XCircle } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode, type RefObject } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api/client';
import { cn } from '@/lib/utils';

export const TARGET = 'min-h-12 h-12 text-base';
export const FIELD = 'h-12 text-lg';
export const MONO = 'font-mono tracking-wide';

/** A field a typed character may land in: anything editable, or a native select. */
function isEditable(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

/**
 * Scan-everywhere: a printable key pressed while nothing editable has
 * focus moves focus to the scan bar before the character is typed, so a
 * wedge scanner's first character is not lost.
 */
export function useScanFocus(scanRef: RefObject<HTMLInputElement | null>, enabled: boolean) {
    useEffect(() => {
        if (!enabled) {
            return;
        }
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1 || isEditable(e.target)) {
                return;
            }
            scanRef.current?.focus();
        };
        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [scanRef, enabled]);
}

export function ScanBar({ id, inputRef, label, busy, onScan }: { id: string; inputRef: RefObject<HTMLInputElement>; label: string; busy: boolean; onScan: (code: string) => void }) {
    const [value, setValue] = useState('');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const code = value.trim();
        if (code !== '' && !busy) {
            onScan(code);
            setValue('');
        }
    };

    return (
        <form onSubmit={submit} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <label htmlFor={id} className="mb-3 flex items-center gap-2 text-base font-semibold">
                <ScanLine className="size-5 text-blue-600" aria-hidden /> {label}
            </label>
            <div className="flex gap-3">
                <Input
                    id={id}
                    ref={inputRef}
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    autoComplete="off"
                    autoCapitalize="characters"
                    spellCheck={false}
                    className={cn('h-14 flex-1 bg-slate-50 text-xl', MONO)}
                    aria-describedby={`${id}-hint`}
                    autoFocus
                />
                <Button type="submit" className={cn(TARGET, 'h-14 px-6')} disabled={busy}>
                    <CornerDownLeft aria-hidden /> Go
                </Button>
            </div>
            <p id={`${id}-hint`} className="mt-2 text-sm text-slate-500">
                Scan, or type and press Enter. Start typing anywhere — it lands here.
            </p>
        </form>
    );
}

export type NoticeTone = 'error' | 'ok' | 'info';

export function Notice({ tone, children }: { tone: NoticeTone; children: ReactNode }) {
    const Icon = tone === 'error' ? XCircle : tone === 'ok' ? CheckCircle2 : Info;

    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={cn(
                'flex items-start gap-3 rounded-xl p-4 text-base ring-1',
                tone === 'error' && 'bg-red-50 text-red-900 ring-red-200',
                tone === 'ok' && 'bg-emerald-50 text-emerald-900 ring-emerald-200',
                tone === 'info' && 'bg-white text-slate-700 shadow-sm ring-slate-200',
            )}
        >
            <Icon
                className={cn('mt-0.5 size-5 shrink-0', tone === 'error' && 'text-red-600', tone === 'ok' && 'text-emerald-600', tone === 'info' && 'text-blue-600')}
                aria-hidden
            />
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}

export function describeError(error: unknown): string {
    if (error instanceof ApiError) {
        return error.message;
    }

    return 'Something went wrong. Check the connection and try again.';
}

export function formatTime(iso: string): string {
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/London', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

/** "3 × Outer of 12 + 5 units", or units alone for an each pack. */
export function packsText(baseQty: number, packLabel: string, packBaseUnits: number): string {
    const units = `${baseQty.toLocaleString('en-GB')} ${baseQty === 1 ? 'unit' : 'units'}`;
    if (packBaseUnits <= 1) {
        return units;
    }
    const packs = Math.floor(baseQty / packBaseUnits);
    const loose = baseQty % packBaseUnits;
    const packPart = `${packs} × ${packLabel}`;

    return loose === 0 ? `${packPart} (${units})` : `${packPart} + ${loose} loose (${units})`;
}
