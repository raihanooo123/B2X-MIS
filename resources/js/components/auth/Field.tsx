/**
 * A labelled form field with its validation message, wired for screen
 * readers (07 §8): the error is linked by aria-describedby and the input
 * marked aria-invalid. 44 px tall on touch screens (05.1 §8.2).
 */
import { useId, type InputHTMLAttributes, type ReactNode } from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
    label: string;
    error?: string;
    hint?: ReactNode;
    labelAside?: ReactNode;
}

export function Field({ label, error, hint, labelAside, className, ...input }: FieldProps) {
    const id = useId();
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-baseline justify-between gap-2">
                <label htmlFor={id} className="text-sm font-medium">
                    {label}
                    {!input.required && <span className="ml-1 font-normal text-muted-foreground">(optional)</span>}
                </label>
                {labelAside}
            </div>
            <Input id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy} className={cn('h-11 md:h-10', error && 'border-red-500')} {...input} />
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="text-xs text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

export function Checkbox({ label, error, checked, onChange }: { label: ReactNode; error?: string; checked: boolean; onChange: (checked: boolean) => void }) {
    const id = useId();

    return (
        <div className="space-y-1">
            <div className="flex items-start gap-2.5">
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    onChange={(e) => onChange(e.target.checked)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className="mt-0.5 size-5 shrink-0 rounded border-input accent-primary"
                />
                <label htmlFor={id} className="text-sm leading-snug">
                    {label}
                </label>
            </div>
            {error && (
                <p id={`${id}-error`} className="pl-7 text-xs text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

export function FormError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
            {message}
        </p>
    );
}
