/**
 * 05.16 §4: on a failed submit, a summary linking to each invalid field,
 * focused so a keyboard or screen-reader user lands on it. Field errors
 * also stay beside their fields.
 */
import { useEffect, useRef } from 'react';

export function ErrorSummary({ errors, fieldIds, title = 'Check the following' }: { errors: Record<string, string>; fieldIds: Record<string, string>; title?: string }) {
    const ref = useRef<HTMLDivElement>(null);
    const entries = Object.entries(errors);

    useEffect(() => {
        if (entries.length > 0) {
            ref.current?.focus();
        }
    }, [entries.length]);

    if (entries.length === 0) {
        return null;
    }

    return (
        <div ref={ref} tabIndex={-1} role="alert" aria-labelledby="error-summary-title" className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950 focus:outline-none focus:ring-2 focus:ring-red-400">
            <h2 id="error-summary-title" className="font-semibold">
                {title}
            </h2>
            <ul className="mt-2 list-disc space-y-1 pl-5">
                {entries.map(([field, message]) => (
                    <li key={field}>
                        {fieldIds[field] ? (
                            <a href={`#${fieldIds[field]}`} className="underline underline-offset-4">
                                {message}
                            </a>
                        ) : (
                            message
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
