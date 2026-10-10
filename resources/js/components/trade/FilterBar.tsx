/**
 * 05.16 §3 filter toolbar: search (debounced 250 ms, `/` focuses it),
 * the list's own filters, applied-filter chips each with a remove
 * button, Clear, and an optional saved-views menu. Filters live in the
 * URL; the page owns them and reloads from the first page on change.
 */
import { Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export interface FilterChip {
    label: string;
    onRemove: () => void;
}

interface FilterBarProps {
    search?: { value: string; onChange: (value: string) => void; label: string; placeholder?: string };
    /** The list's selects (status, dates, …), each with its own visible label. */
    children?: ReactNode;
    chips: FilterChip[];
    onClear: () => void;
    savedViews?: ReactNode;
}

export const SEARCH_INPUT_ATTRIBUTE = 'data-list-search';

export function FilterBar({ search, children, chips, onClear, savedViews }: FilterBarProps) {
    const searchId = useId();
    const [text, setText] = useState(search?.value ?? '');
    const latest = useRef(search?.onChange);
    latest.current = search?.onChange;

    // Follow the URL when it changes underneath (back button, Clear, a saved view).
    useEffect(() => setText(search?.value ?? ''), [search?.value]);

    useEffect(() => {
        if (search === undefined || text === search.value) {
            return;
        }
        const timer = window.setTimeout(() => latest.current?.(text), 250);

        return () => window.clearTimeout(timer);
    }, [text, search]);

    return (
        <section aria-label="Filters" className="mb-4 flex flex-col gap-3 rounded-xl border bg-background p-4">
            <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end">
                {search && (
                    <div className="flex min-w-0 flex-1 flex-col gap-1.5 md:min-w-64">
                        <label htmlFor={searchId} className="text-sm font-medium">
                            {search.label}
                        </label>
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                            <Input
                                id={searchId}
                                type="search"
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                placeholder={search.placeholder}
                                className="h-11 pl-9"
                                {...{ [SEARCH_INPUT_ATTRIBUTE]: '' }}
                                aria-keyshortcuts="/"
                            />
                        </div>
                    </div>
                )}
                {/* Two to a row on phones, so the filters do not push the list off screen. */}
                <div className="grid grid-cols-2 gap-3 md:contents [&>*:last-child:nth-child(odd)]:col-span-2">{children}</div>
                {savedViews}
            </div>
            {chips.length > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-muted-foreground">Applied:</span>
                    <ul className="flex flex-wrap gap-2">
                        {chips.map((chip) => (
                            <li key={chip.label}>
                                <button
                                    type="button"
                                    onClick={chip.onRemove}
                                    className="inline-flex min-h-11 items-center gap-1.5 rounded-full border bg-muted px-3 text-sm hover:bg-muted/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:min-h-9"
                                >
                                    {chip.label}
                                    <X className="size-3.5" aria-hidden />
                                    <span className="sr-only">Remove filter</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Button variant="link" className="h-11 px-2 md:h-9" onClick={onClear}>
                        Clear all
                    </Button>
                </div>
            )}
        </section>
    );
}

/** A labelled native select, 44 px high: keyboard and screen reader behaviour for free. */
export function FilterSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: { value: string; label: string }[] }) {
    const id = useId();

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <select id={id} value={value} onChange={(e) => onChange(e.target.value)} className="h-11 rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

/** A labelled date input (UK calendar day; the value is ISO yyyy-mm-dd). */
export function FilterDate({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
    const id = useId();

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <Input id={id} type="date" value={value} onChange={(e) => onChange(e.target.value)} className="h-11" />
        </div>
    );
}
