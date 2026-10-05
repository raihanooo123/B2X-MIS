/**
 * 05.16 §3–4 / 06 §5.1: a server-filtered keyset list on an Inertia page.
 *
 *   - filters live in the URL; changing one (or the sort) is a fresh visit
 *     from the first page — the cursor is bound to the filters — and the
 *     list shows its skeleton meanwhile (`refreshing`);
 *   - "Load more" is a partial reload of just the rows and the next cursor,
 *     merged by the server onto the rows shown (Inertia::merge), the URL
 *     left as it is, so a refresh shows the first page again;
 *   - a failed visit becomes a recoverable error with Retry and the
 *     server's request reference, instead of Inertia's error modal.
 */
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Filters = Record<string, string | null>;

export interface ListFailure {
    message: string;
    reference: string | null;
}

function query(values: Filters): Record<string, string> {
    return Object.fromEntries(Object.entries(values).filter((entry): entry is [string, string] => entry[1] !== null && entry[1] !== ''));
}

export function useKeysetList(filters: Filters, nextCursor: string | null, props: { rows: string; cursor: string } = { rows: 'rows', cursor: 'next_cursor' }) {
    const [loadingMore, setLoadingMore] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [failure, setFailure] = useState<ListFailure | null>(null);
    const ours = useRef(false);
    const lastVisit = useRef<(() => void) | null>(null);

    // Only failures of this list's own visits are caught here.
    useEffect(() => {
        const offInvalid = router.on('invalid', (event) => {
            if (!ours.current) {
                return;
            }
            event.preventDefault();
            const data = event.detail.response.data as { error?: { request_id?: string } } | undefined;
            setFailure({ message: `The server answered ${event.detail.response.status}. Nothing was changed.`, reference: data?.error?.request_id ?? null });
        });
        const offException = router.on('exception', (event) => {
            if (!ours.current) {
                return;
            }
            event.preventDefault();
            setFailure({ message: 'The connection failed. Nothing was changed.', reference: null });
        });

        return () => {
            offInvalid();
            offException();
        };
    }, []);

    const visit = useCallback((run: () => void) => {
        lastVisit.current = run;
        setFailure(null);
        run();
    }, []);

    const setFilters = useCallback(
        (changes: Filters) => {
            visit(() =>
                router.get(window.location.pathname, query({ ...filters, ...changes }), {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onStart: () => {
                        ours.current = true;
                        setRefreshing(true);
                    },
                    onFinish: () => {
                        ours.current = false;
                        setRefreshing(false);
                    },
                }),
            );
        },
        [filters, visit],
    );

    const loadMore = useCallback(() => {
        if (nextCursor === null || loadingMore) {
            return;
        }
        visit(() =>
            router.reload({
                only: [props.rows, props.cursor],
                data: { ...query(filters), cursor: nextCursor },
                preserveUrl: true,
                onStart: () => {
                    ours.current = true;
                    setLoadingMore(true);
                },
                onFinish: () => {
                    ours.current = false;
                    setLoadingMore(false);
                },
            }),
        );
    }, [filters, nextCursor, loadingMore, props.rows, props.cursor, visit]);

    const retry = useCallback(() => lastVisit.current?.(), []);

    return { setFilters, loadMore, loadingMore, refreshing, failure, retry, hasMore: nextCursor !== null };
}
