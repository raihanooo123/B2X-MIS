/**
 * 05.16 §4: action toasts in one polite live region. Text plus icon,
 * never colour alone; a 44 px dismiss; six seconds, held while hovered or
 * focused. Errors that need action also stay inline on the page.
 */
import { CheckCircle2, Info, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { cn } from '@/lib/utils';
import { useToastStore, type Toast } from '@/stores/toastStore';

const TONE = {
    success: { icon: CheckCircle2, className: 'border-emerald-300 bg-emerald-50 text-emerald-950', label: 'Done' },
    error: { icon: TriangleAlert, className: 'border-red-300 bg-red-50 text-red-950', label: 'Problem' },
    info: { icon: Info, className: 'border-sky-300 bg-sky-50 text-sky-950', label: 'Note' },
} as const;

function ToastItem({ toast }: { toast: Toast }) {
    const dismiss = useToastStore((s) => s.dismiss);
    const [held, setHeld] = useState(false);
    const tone = TONE[toast.tone];

    useEffect(() => {
        if (held) {
            return;
        }
        const timer = window.setTimeout(() => dismiss(toast.id), 6000);

        return () => window.clearTimeout(timer);
    }, [held, dismiss, toast.id]);

    return (
        <li
            className={cn('pointer-events-auto flex w-full items-start gap-3 rounded-lg border p-3 pr-1 shadow-lg motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-2', tone.className)}
            onMouseEnter={() => setHeld(true)}
            onMouseLeave={() => setHeld(false)}
            onFocus={() => setHeld(true)}
            onBlur={() => setHeld(false)}
        >
            <tone.icon className="mt-0.5 size-5 shrink-0" aria-hidden />
            <div className="min-w-0 flex-1 py-0.5 text-sm">
                <p className="font-medium">
                    <span className="sr-only">{tone.label}: </span>
                    {toast.title}
                </p>
                {toast.description && <p className="mt-0.5 opacity-90">{toast.description}</p>}
            </div>
            <button type="button" onClick={() => dismiss(toast.id)} className="inline-flex size-11 shrink-0 items-center justify-center rounded-md hover:bg-black/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label="Dismiss">
                <X className="size-4" aria-hidden />
            </button>
        </li>
    );
}

export function Toaster() {
    const toasts = useToastStore((s) => s.toasts);

    return (
        <div role="status" aria-live="polite" className="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex justify-center sm:inset-x-auto sm:right-6 sm:justify-end">
            <ol className="flex w-full max-w-sm flex-col gap-2">
                {toasts.map((t) => (
                    <ToastItem key={t.id} toast={t} />
                ))}
            </ol>
        </div>
    );
}
