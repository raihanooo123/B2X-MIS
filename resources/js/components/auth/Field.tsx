/**
 * A labelled form field with its validation message, wired for screen
 * readers (07 §8): the error is linked by aria-describedby and the input
 * marked aria-invalid. 44 px tall on touch screens (05.1 §8.2).
 */
import { useId, useState, type InputHTMLAttributes, type ReactNode } from 'react';

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
    const [visible, setVisible] = useState(false);
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
            <div className="relative">
                <Input id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy} className={cn('h-11 md:h-10', input.type === 'password' && 'pr-20', error && 'border-red-500')} {...input} type={input.type === 'password' && visible ? 'text' : input.type} />
                {input.type === 'password' && (
                    <button type="button" aria-controls={id} aria-label={`${visible ? 'Hide' : 'Show'} ${label.toLowerCase()}`} aria-pressed={visible} onClick={() => setVisible((value) => !value)} className="absolute inset-y-0 right-0 rounded-r-md px-3 text-sm font-medium focus-visible:outline focus-visible:outline-2">
                        {visible ? 'Hide' : 'Show'}
                    </button>
                )}
            </div>
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
            {/* The whole row is the label, so the tap target is 44px tall, not the 20px box. */}
            <label htmlFor={id} className="flex min-h-11 cursor-pointer items-start gap-2.5 py-2.5">
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    onChange={(e) => onChange(e.target.checked)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className="mt-0.5 size-5 shrink-0 rounded border-input accent-primary"
                />
                <span className="text-sm leading-snug">{label}</span>
            </label>
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
