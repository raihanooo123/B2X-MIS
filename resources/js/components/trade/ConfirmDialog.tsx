/**
 * 05.16 §4: the confirm dialog for destructive or money actions. It says
 * which company, which records, the amount, the consequences, and uses
 * the action's own verb; Cancel has the default focus. Focus is trapped
 * and restored by Radix. The dialog stays open, with the button pending,
 * until the server answers — success is never shown optimistically — and
 * a refusal stays inline in the dialog.
 */
import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * White on red-700 (6.5:1). The shared `destructive` token is red-500,
 * 3.8:1 with white — below 05.16 §5's 4.5:1 for button text.
 */
export const DANGER_BUTTON = 'bg-red-700 text-white hover:bg-red-800 focus-visible:ring-red-700';

export interface ConfirmFact {
    label: string;
    value: ReactNode;
}

interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** Company, records, amount — whatever this decision commits. */
    facts: ConfirmFact[];
    consequences: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    pending: boolean;
    /** A refusal from the server, kept in the dialog. */
    error?: string | null;
    onConfirm: () => void;
    /** Extra inputs, e.g. a required reason. */
    children?: ReactNode;
    confirmDisabled?: boolean;
}

export function ConfirmDialog({ open, onOpenChange, title, facts, consequences, confirmLabel, destructive = false, pending, error, onConfirm, children, confirmDisabled = false }: ConfirmDialogProps) {
    return (
        <AlertDialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
            <AlertDialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-y-auto">
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <dl className="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-1.5 rounded-lg border bg-muted/40 p-3 text-foreground">
                                {facts.map((fact) => (
                                    <div key={fact.label} className="contents">
                                        <dt className="text-muted-foreground">{fact.label}</dt>
                                        <dd className="min-w-0 break-words font-medium">{fact.value}</dd>
                                    </div>
                                ))}
                            </dl>
                            <div>{consequences}</div>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {children}
                {error && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">
                        {error}
                    </p>
                )}
                <AlertDialogFooter className="gap-2">
                    <AlertDialogCancel className="h-11" disabled={pending}>
                        Cancel
                    </AlertDialogCancel>
                    <Button className={cn('h-11', destructive && DANGER_BUTTON)} onClick={onConfirm} disabled={pending || confirmDisabled} aria-busy={pending}>
                        {pending && <Loader2 className="animate-spin" aria-hidden />}
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
