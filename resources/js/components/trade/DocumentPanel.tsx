/**
 * 05.17 §4 — the PDF panel on an invoice, credit note or statement page.
 * The page's own details never depend on it: while a PDF is queued the
 * panel shows a skeleton and polls (a GET, which never queues); a failure
 * shows safe text and Retry; a ready archive shows Download (a private,
 * no-store browser download, re-authorised by the server each time).
 */
import { Download, FileText, Loader2, RefreshCw, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api/client';
import { documentStatus, prepareDocument, retryDocument, type DocumentState, type DocumentType } from '@/lib/api/documents';

const POLL_MS = 2000;
const POLL_LIMIT = 60;

export function DocumentPanel({ type, source, initial, label }: { type: DocumentType; source: string; initial: DocumentState; label: string }) {
    const [state, setState] = useState<DocumentState>(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const polls = useRef(0);

    const working = state.status === 'rendering' || (state.status === 'pending' && polls.current > 0);

    // Poll while the render is queued or running, within a bound.
    useEffect(() => {
        if (state.id === null || !(state.status === 'pending' || state.status === 'rendering') || polls.current === 0 || polls.current >= POLL_LIMIT) {
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            polls.current += 1;
            documentStatus(state.id as string, controller.signal)
                .then((r) => setState(r.data))
                .catch((e: unknown) => {
                    if (!(e instanceof DOMException)) {
                        setError(e instanceof ApiError ? e.message : 'The connection failed. Try again.');
                    }
                });
        }, POLL_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [state]);

    const run = async (action: () => Promise<{ data: DocumentState }>) => {
        setBusy(true);
        setError(null);
        try {
            const result = await action();
            polls.current = 1;
            setState(result.data);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'The connection failed. Try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="pdf-heading" className="rounded-xl border bg-background p-5">
            <h2 id="pdf-heading" className="mb-3 flex items-center gap-2 text-base font-semibold">
                <FileText className="size-5 text-muted-foreground" aria-hidden /> PDF copy
            </h2>

            <div aria-live="polite">
                {state.status === 'ready' && state.download_url ? (
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-muted-foreground">The archived copy of this {label}, exactly as issued.</p>
                        <Button asChild className="h-11 w-full sm:w-auto">
                            <a href={state.download_url}>
                                <Download aria-hidden /> Download PDF
                            </a>
                        </Button>
                    </div>
                ) : state.status === 'none' ? (
                    <p className="text-sm text-muted-foreground">{state.message}</p>
                ) : state.status === 'failed' ? (
                    <div role="alert" className="flex flex-col gap-3">
                        <p className="flex gap-2 text-sm text-red-900">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> {state.message}
                        </p>
                        <Button variant="outline" className="h-11 w-full sm:w-auto" disabled={busy || state.id === null} onClick={() => state.id && run(() => retryDocument(state.id as string))}>
                            {busy ? <Loader2 className="animate-spin" aria-hidden /> : <RefreshCw aria-hidden />} Try again
                        </Button>
                    </div>
                ) : working ? (
                    <div role="status" className="flex flex-col gap-3">
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" aria-hidden /> Preparing the PDF. You can keep working; this page updates when it is ready.
                        </p>
                        <Skeleton className="h-11 w-48" />
                    </div>
                ) : (
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-muted-foreground">The PDF is prepared from the {label} as it was issued.</p>
                        <Button className="h-11 w-full sm:w-auto" disabled={busy} onClick={() => run(() => prepareDocument(type, source))}>
                            {busy ? <Loader2 className="animate-spin" aria-hidden /> : <FileText aria-hidden />} Prepare PDF
                        </Button>
                    </div>
                )}
                {error && (
                    <p role="alert" className="mt-3 text-sm text-red-900">
                        {error}
                    </p>
                )}
                {polls.current >= POLL_LIMIT && state.status !== 'ready' && (
                    <p className="mt-3 text-sm text-muted-foreground">
                        This is taking longer than usual. Reload the page later to check again.
                    </p>
                )}
            </div>
        </section>
    );
}
