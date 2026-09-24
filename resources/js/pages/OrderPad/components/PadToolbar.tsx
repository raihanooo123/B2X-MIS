/**
 * The order pad's search and filter bar (05.1 §4.1, §3 U2): search on SKU
 * code and product name, category, brand, "in stock only".
 *
 * All filtering is server-side (OrderPadCatalogue): each change is an
 * Inertia partial reload of `catalogue` and `filters` only, with the
 * filters in the URL so a filtered pad can be bookmarked or shared.
 * Search waits for a pause in typing (SEARCH_DEBOUNCE_MS) rather than
 * reloading per keystroke; a newer visit cancels an older one in flight.
 * Typed quantities live in the pad store and survive every change here.
 *
 * `/` focuses the search box from anywhere (05.1 §8.1).
 */
import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { bindSearchShortcut, SEARCH_INPUT_ID } from '@/lib/keyboard/tabOrder';
import { cn } from '@/lib/utils';

import type { PadFacets, PadFilters } from '../types';

const SEARCH_DEBOUNCE_MS = 300;
/** Radix Select reserves the empty string, so "no filter" needs a sentinel. */
const ALL = '__all';

/** The query string for a set of filters, blanks omitted. */
export function filterQuery(filters: PadFilters): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.q) query.q = filters.q;
    if (filters.category) query.category = filters.category;
    if (filters.brand) query.brand = filters.brand;
    if (filters.in_stock) query.in_stock = '1';

    return query;
}

export function hasActiveFilters(filters: PadFilters): boolean {
    return Object.keys(filterQuery(filters)).length > 0;
}

export function visitWithFilters(filters: PadFilters): void {
    router.get('/order-pad', filterQuery(filters), {
        only: ['catalogue', 'filters'],
        preserveState: true,
        preserveScroll: false,
        replace: true,
    });
}

export function PadToolbar({ filters, facets }: { filters: PadFilters; facets: PadFacets }) {
    const [search, setSearch] = useState(filters.q ?? '');
    const lastSent = useRef(filters.q ?? '');

    // A change that did not come from typing here (clear all, back button)
    // resets the box to what the server applied.
    useEffect(() => {
        const applied = filters.q ?? '';
        if (applied !== lastSent.current) {
            lastSent.current = applied;
            setSearch(applied);
        }
    }, [filters.q]);

    useEffect(() => {
        const term = search.trim();
        if (term === (filters.q ?? '')) {
            return;
        }
        const timer = window.setTimeout(() => {
            lastSent.current = term;
            visitWithFilters({ ...filters, q: term === '' ? null : term });
        }, SEARCH_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [search, filters]);

    useEffect(() => bindSearchShortcut(), []);

    const set = (patch: Partial<PadFilters>) => visitWithFilters({ ...filters, q: search.trim() === '' ? null : search.trim(), ...patch });

    return (
        <div className="mb-3 flex flex-col gap-2 md:flex-row md:items-center">
            <div className="relative md:w-80">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                <Input
                    id={SEARCH_INPUT_ID}
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape' && search !== '') {
                            e.preventDefault();
                            setSearch('');
                        }
                    }}
                    placeholder="Search by SKU code or product name"
                    aria-label="Search products by SKU code or name"
                    aria-keyshortcuts="/"
                    autoComplete="off"
                    maxLength={100}
                    enterKeyHint="search"
                    className="h-11 pl-8 pr-10 md:h-9"
                />
                <kbd className="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 rounded border bg-muted px-1.5 font-mono text-[11px] text-muted-foreground md:block" aria-hidden>
                    /
                </kbd>
            </div>

            <div className="grid grid-cols-2 gap-2 md:flex md:items-center">
                <Select value={filters.category ?? ALL} onValueChange={(v) => set({ category: v === ALL ? null : v })}>
                    <SelectTrigger className="h-11 md:h-9 md:w-48" aria-label="Category">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL} className="min-h-11 md:min-h-0">
                            All categories
                        </SelectItem>
                        {facets.categories.map((c) => (
                            <SelectItem key={c.slug} value={c.slug} className="min-h-11 md:min-h-0">
                                <span style={{ paddingLeft: `${c.depth * 0.75}rem` }}>{c.name}</span>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select value={filters.brand ?? ALL} onValueChange={(v) => set({ brand: v === ALL ? null : v })}>
                    <SelectTrigger className="h-11 md:h-9 md:w-44" aria-label="Brand">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL} className="min-h-11 md:min-h-0">
                            All brands
                        </SelectItem>
                        {facets.brands.map((b) => (
                            <SelectItem key={b.slug} value={b.slug} className="min-h-11 md:min-h-0">
                                {b.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Button
                    type="button"
                    variant="outline"
                    role="switch"
                    aria-checked={filters.in_stock}
                    onClick={() => set({ in_stock: !filters.in_stock })}
                    className={cn('h-11 justify-start gap-2 md:h-9', filters.in_stock && 'border-emerald-600 bg-emerald-50 text-emerald-800 hover:bg-emerald-100')}
                >
                    <span className={cn('flex size-4 items-center justify-center rounded-sm border', filters.in_stock ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-input')} aria-hidden>
                        {filters.in_stock && '✓'}
                    </span>
                    In stock only
                </Button>

                {hasActiveFilters(filters) && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            setSearch('');
                            visitWithFilters({ q: null, category: null, brand: null, in_stock: false });
                        }}
                        className="h-11 md:h-9"
                    >
                        <X /> Clear filters
                    </Button>
                )}
            </div>
        </div>
    );
}
