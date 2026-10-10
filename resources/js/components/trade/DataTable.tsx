/**
 * 05.16 §3 — the shared list: a semantic table from 1024 px (sticky
 * labelled header under the 64 px top bar, sortable headers exposing
 * `aria-sort`, secondary columns from 1280 px), and below it labelled
 * cards carrying the same essential data and actions, secondary detail
 * behind a disclosure — nothing is removed on mobile. No horizontal page
 * scroll: cells wrap instead of squeezing.
 *
 * Bulk selection is of the *loaded rows only*, and says so; the server
 * revalidates every record. "Load more" is keyset (06 §5.1), never OFFSET.
 */
import { ArrowDown, ArrowUp, ArrowUpDown, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface Column<T> {
    id: string;
    header: string;
    cell: (row: T) => ReactNode;
    /** Money, quantities and percentages align right with tabular figures (05.16 §5). */
    align?: 'start' | 'end';
    /** The server's sort key; makes the header a sort button. */
    sortKey?: string;
    /** `secondary`: from 1280 px in the table, and under "More details" on a card. */
    priority?: 'essential' | 'secondary';
    /** Card label, when the header alone is unclear out of context. */
    cardLabel?: string;
    /** Already shown in the card's heading (cardTitle), so not repeated in its body. */
    inCardTitle?: boolean;
    className?: string;
}

export interface SortState {
    key: string;
    direction: 'asc' | 'desc';
}

export interface Selection<T> {
    selected: ReadonlySet<string>;
    onChange: (next: Set<string>) => void;
    isSelectable?: (row: T) => boolean;
    /** Bulk actions for the selected rows. */
    actions: ReactNode;
}

interface DataTableProps<T> {
    /** The table's accessible name. */
    caption: string;
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string;
    /** The card's heading on mobile (usually the reference). */
    cardTitle: (row: T) => ReactNode;
    /** Plain text naming the row, for its checkbox ("Select SO-1042"). */
    rowLabel: (row: T) => string;
    /** The row's one primary action (e.g. "Open request"). */
    rowAction?: (row: T) => ReactNode;
    sort?: SortState | null;
    onSort?: (key: string) => void;
    selection?: Selection<T>;
    loadMore?: { hasMore: boolean; loading: boolean; onLoadMore: () => void; label?: string };
    /** Keyboard row navigation (j/k): the row index shown as active. */
    activeIndex?: number | null;
}

function SortIcon({ direction }: { direction: 'asc' | 'desc' | null }) {
    const Icon = direction === 'asc' ? ArrowUp : direction === 'desc' ? ArrowDown : ArrowUpDown;

    return <Icon className={cn('size-3.5 shrink-0', direction === null && 'opacity-50')} aria-hidden />;
}

export function DataTable<T>({ caption, columns, rows, rowKey, cardTitle, rowLabel, rowAction, sort = null, onSort, selection, loadMore, activeIndex = null }: DataTableProps<T>) {
    const selectable = selection ? rows.filter((r) => selection.isSelectable?.(r) ?? true) : [];
    const selectedLoaded = selection ? selectable.filter((r) => selection.selected.has(rowKey(r))).length : 0;
    const allSelected = selection !== undefined && selectable.length > 0 && selectedLoaded === selectable.length;

    const toggle = (key: string) => {
        if (!selection) {
            return;
        }
        const next = new Set(selection.selected);
        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }
        selection.onChange(next);
    };

    const toggleAll = () => selection?.onChange(allSelected ? new Set() : new Set(selectable.map(rowKey)));

    const headerCell = (column: Column<T>) => {
        const direction = sort !== null && sort.key === column.sortKey ? sort.direction : null;
        const ariaSort = column.sortKey ? (direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none') : undefined;

        return (
            <th
                key={column.id}
                scope="col"
                aria-sort={ariaSort}
                className={cn(
                    'sticky top-16 z-10 border-b bg-muted px-4 py-3 text-xs font-semibold uppercase tracking-wide text-foreground/80',
                    column.align === 'end' ? 'text-right' : 'text-left',
                    column.priority === 'secondary' && 'hidden xl:table-cell',
                    column.className,
                )}
            >
                {column.sortKey && onSort ? (
                    <button
                        type="button"
                        onClick={() => onSort(column.sortKey as string)}
                        className={cn('-mx-2 inline-flex min-h-11 items-center gap-1.5 rounded px-2 uppercase hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', column.align === 'end' && 'flex-row-reverse')}
                    >
                        {column.header}
                        <SortIcon direction={direction} />
                    </button>
                ) : (
                    column.header
                )}
            </th>
        );
    };

    const essential = columns.filter((c) => c.priority !== 'secondary' && !c.inCardTitle);
    const secondary = columns.filter((c) => c.priority === 'secondary');

    return (
        <div className="flex flex-col gap-3">
            {selection && selection.selected.size > 0 && (
                <div role="region" aria-label="Bulk actions" className="sticky top-16 z-20 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-background px-4 py-2 shadow-sm">
                    <p className="text-sm" aria-live="polite">
                        <span className="font-semibold tabular-nums">{selection.selected.size}</span> selected
                        <span className="text-muted-foreground"> of {rows.length} loaded rows only</span>
                    </p>
                    <Button variant="ghost" className="h-11" onClick={() => selection.onChange(new Set())}>
                        Clear selection
                    </Button>
                    <div className="ml-auto flex flex-wrap gap-2">{selection.actions}</div>
                </div>
            )}

            {/* Desktop: one semantic table, no horizontal scroll container, so the header stays sticky. */}
            <div className="hidden rounded-xl border bg-background lg:block">
                <table className="w-full border-separate border-spacing-0 text-sm">
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr>
                            {selection && (
                                <th scope="col" className="sticky top-16 z-10 w-12 rounded-tl-xl border-b bg-muted px-4 py-3">
                                    <input
                                        type="checkbox"
                                        className="size-5 accent-primary"
                                        checked={allSelected}
                                        disabled={selectable.length === 0}
                                        onChange={toggleAll}
                                        aria-label={allSelected ? 'Clear selection of loaded rows' : 'Select all loaded rows'}
                                    />
                                </th>
                            )}
                            {columns.map(headerCell)}
                            {rowAction && (
                                <th scope="col" className="sticky top-16 z-10 rounded-tr-xl border-b bg-muted px-4 py-3 text-right">
                                    <span className="sr-only">Actions</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => {
                            const key = rowKey(row);
                            const canSelect = selection?.isSelectable?.(row) ?? true;

                            return (
                                <tr key={key} data-row-index={index} data-active={activeIndex === index || undefined} className="group data-[active]:bg-primary/5 hover:bg-muted/40">
                                    {selection && (
                                        <td className="border-b px-4 py-3 align-top group-last:border-0">
                                            {canSelect && <input type="checkbox" className="size-5 accent-primary" checked={selection.selected.has(key)} onChange={() => toggle(key)} aria-label={`Select ${rowLabel(row)}`} />}
                                        </td>
                                    )}
                                    {columns.map((column) => (
                                        <td
                                            key={column.id}
                                            className={cn(
                                                'break-words border-b px-4 py-3 align-top group-last:border-0',
                                                column.align === 'end' && 'text-right tabular-nums',
                                                column.priority === 'secondary' && 'hidden xl:table-cell',
                                                column.className,
                                            )}
                                        >
                                            {column.cell(row)}
                                        </td>
                                    ))}
                                    {rowAction && <td className="whitespace-nowrap border-b px-4 py-2 text-right align-top group-last:border-0">{rowAction(row)}</td>}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* Below 1024 px: labelled cards with the same data and actions. */}
            <ul className="flex flex-col gap-3 lg:hidden" aria-label={caption}>
                {selection && selectable.length > 0 && (
                    <li>
                        <label className="flex min-h-11 items-center gap-3 rounded-lg border bg-background px-4 text-sm">
                            <input type="checkbox" className="size-5 accent-primary" checked={allSelected} onChange={toggleAll} />
                            Select all {selectable.length} loaded rows
                        </label>
                    </li>
                )}
                {rows.map((row, index) => {
                    const key = rowKey(row);
                    const canSelect = selection?.isSelectable?.(row) ?? true;

                    return (
                        <li key={key} data-row-index={index} data-active={activeIndex === index || undefined} className="rounded-xl border bg-background p-4 data-[active]:ring-2 data-[active]:ring-primary/40">
                            <div className="flex items-start gap-3">
                                {selection && canSelect && (
                                    <input type="checkbox" className="mt-0.5 size-5 shrink-0 accent-primary" checked={selection.selected.has(key)} onChange={() => toggle(key)} aria-label={`Select ${rowLabel(row)}`} />
                                )}
                                <h3 className="min-w-0 flex-1 break-words font-semibold">{cardTitle(row)}</h3>
                            </div>
                            <dl className="mt-3 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                                {essential.map((column) => (
                                    <div key={column.id} className="contents">
                                        <dt className="text-muted-foreground">{column.cardLabel ?? column.header}</dt>
                                        <dd className={cn('min-w-0 break-words', column.align === 'end' && 'text-right tabular-nums')}>{column.cell(row)}</dd>
                                    </div>
                                ))}
                            </dl>
                            {secondary.length > 0 && (
                                <details className="mt-3 text-sm">
                                    <summary className="flex min-h-11 cursor-pointer items-center text-muted-foreground hover:text-foreground">More details</summary>
                                    <dl className="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-2">
                                        {secondary.map((column) => (
                                            <div key={column.id} className="contents">
                                                <dt className="text-muted-foreground">{column.cardLabel ?? column.header}</dt>
                                                <dd className={cn('min-w-0 break-words', column.align === 'end' && 'text-right tabular-nums')}>{column.cell(row)}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </details>
                            )}
                            {rowAction && <div className="mt-3 flex [&>*]:w-full">{rowAction(row)}</div>}
                        </li>
                    );
                })}
            </ul>

            {loadMore?.hasMore && (
                <div className="flex justify-center">
                    <Button variant="outline" className="h-11 min-w-40" onClick={loadMore.onLoadMore} disabled={loadMore.loading}>
                        {loadMore.loading ? <Loader2 className="animate-spin" aria-hidden /> : null}
                        {loadMore.loading ? 'Loading…' : (loadMore.label ?? 'Load more')}
                    </Button>
                </div>
            )}
        </div>
    );
}
