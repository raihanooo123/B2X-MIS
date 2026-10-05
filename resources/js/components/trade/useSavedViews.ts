/**
 * 05.16 §3: named personal saved views for recurring operational lists —
 * browser-local, scoped to user, company and list, holding only status,
 * date and sort choices (never free-text searches, customer or financial
 * data), and cleared on sign-out. Storage can be unavailable (private
 * windows, blocked site data): every access is guarded and the list still
 * works without it.
 */
import { useCallback, useEffect, useState } from 'react';

const PREFIX = 'b2b.views.';

export type SavedViewValues = Record<string, string | null>;

export interface SavedView {
    name: string;
    values: SavedViewValues;
}

function read(key: string): SavedView[] {
    try {
        const raw = window.localStorage.getItem(key);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(parsed) ? (parsed as SavedView[]).filter((v) => typeof v?.name === 'string' && typeof v.values === 'object') : [];
    } catch {
        return [];
    }
}

function write(key: string, views: SavedView[]): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(views));
    } catch {
        // Storage refused: the view lasts for this page only.
    }
}

/** Sign-out clears every saved view on this browser (05.16 §3). */
export function clearSavedViews(): void {
    try {
        Object.keys(window.localStorage)
            .filter((k) => k.startsWith(PREFIX))
            .forEach((k) => window.localStorage.removeItem(k));
    } catch {
        // Nothing stored, or storage unavailable.
    }
}

/**
 * @param scope  user/company/list, e.g. `${userEmail}|${companyId}|approvals`
 * @param allowed the filter keys a view may save; anything else is dropped
 */
export function useSavedViews(scope: string, allowed: string[]) {
    const key = PREFIX + scope;
    const [views, setViews] = useState<SavedView[]>([]);

    useEffect(() => setViews(read(key)), [key]);

    const save = useCallback(
        (name: string, values: SavedViewValues) => {
            const kept = Object.fromEntries(Object.entries(values).filter(([k]) => allowed.includes(k)));
            const next = [...read(key).filter((v) => v.name !== name), { name, values: kept }].slice(-10);
            write(key, next);
            setViews(next);
        },
        [key, allowed],
    );

    const remove = useCallback(
        (name: string) => {
            const next = read(key).filter((v) => v.name !== name);
            write(key, next);
            setViews(next);
        },
        [key],
    );

    return { views, save, remove };
}
