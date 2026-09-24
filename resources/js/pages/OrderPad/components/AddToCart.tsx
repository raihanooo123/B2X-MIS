/**
 * "Add to cart" for the pad: every typed line, on every page and under
 * any filter, sent in one POST /api/v1/cart/bulk-add.
 *
 * bulk-add is all-or-nothing (BulkAddCartRequest): one refused line and
 * nothing is added, with a reason per refused line keyed by its index in
 * the request. The result is shown as a reconciliation (05.1 §7):
 *
 *   - Added: those lines are in the server-side cart now, so they leave
 *     the pad — typing them again would add them again.
 *   - Refused: *every* quantity stays typed; refused rows are flagged
 *     with their reason, and the buyer can fix them or add the rest
 *     ("fix the failures, add the rest" — the client resending without
 *     them, as the endpoint expects).
 *   - Any other failure (network, 5xx): quantities stay, nothing changes.
 *
 * Each line carries `base_qty` as the cross-check the endpoint accepts,
 * so a pack whose size changed since the page loaded is refused rather
 * than silently ordered in the wrong quantity.
 */
import { CircleAlert, CircleCheck, Loader2, ShoppingCart, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import type { ApiError } from '@/lib/api/client';
import { useBulkAddToCart, type CartItemInput } from '@/lib/api/orderPad';
import { ADD_TO_CART_ID } from '@/lib/keyboard/tabOrder';
import { baseQtyOf } from '@/lib/pricing/localRecompute';
import { typedLines, useOrderPadStore } from '@/stores/orderPadStore';

type TypedLine = ReturnType<typeof typedLines>[number];

interface Refused {
    line: TypedLine;
    message: string;
}

type Outcome =
    | { kind: 'added'; lines: TypedLine[]; stillRefused: number }
    | { kind: 'refused'; refused: Refused[]; acceptable: TypedLine[]; message: string }
    | { kind: 'failed'; message: string };

function toInput(line: TypedLine): CartItemInput {
    return { sku_id: line.skuId, pack_code: line.packCode, pack_qty: line.packQty, base_qty: baseQtyOf(line.packQty, line.packBaseUnits) };
}

function describe(line: TypedLine): string {
    return `${line.packQty.toLocaleString('en-GB')} × ${line.packLabel ?? 'pack'}`;
}

/** Refusals by line, from `details[].meta.line_index`. */
function refusalsFor(error: ApiError, sent: TypedLine[]): Refused[] {
    const byIndex = new Map<number, string[]>();
    for (const d of error.details) {
        const index = d.meta?.line_index;
        if (typeof index === 'number' && sent[index] !== undefined) {
            byIndex.set(index, [...(byIndex.get(index) ?? []), d.message]);
        }
    }

    return [...byIndex.entries()].sort(([a], [b]) => a - b).map(([index, messages]) => ({ line: sent[index], message: messages.join(' ') }));
}

export function useAddToCart() {
    const drafts = useOrderPadStore((s) => s.drafts);
    const clearDrafts = useOrderPadStore((s) => s.clearDrafts);
    const setRejections = useOrderPadStore((s) => s.setRejections);
    const mutation = useBulkAddToCart();
    const [outcome, setOutcome] = useState<Outcome | null>(null);

    const lines = typedLines(drafts);

    const send = (sent: TypedLine[], stillRefused: number) => {
        if (sent.length === 0) {
            return;
        }
        mutation.mutate(sent.map(toInput), {
            onSuccess: () => {
                clearDrafts(sent.map((l) => l.skuId));
                setOutcome({ kind: 'added', lines: sent, stillRefused });
            },
            onError: (error) => {
                const refused = error.code === 'invalid_cart_item' ? refusalsFor(error, sent) : [];
                if (refused.length === 0) {
                    setOutcome({ kind: 'failed', message: error.message });

                    return;
                }
                setRejections(Object.fromEntries(refused.map((r) => [r.line.skuId, r.message])));
                const refusedIds = new Set(refused.map((r) => r.line.skuId));
                setOutcome({ kind: 'refused', refused, acceptable: sent.filter((l) => !refusedIds.has(l.skuId)), message: error.message });
            },
        });
    };

    return {
        lines,
        pending: mutation.isPending,
        outcome,
        dismiss: () => setOutcome(null),
        addAll: () => send(lines, 0),
        // With the quantities as they are now, not as they were refused:
        // the buyer may have edited them since.
        addAcceptable: () => {
            if (outcome?.kind === 'refused') {
                const ids = new Set(outcome.acceptable.map((l) => l.skuId));
                send(
                    lines.filter((l) => ids.has(l.skuId)),
                    outcome.refused.length,
                );
            }
        },
    };
}

export function AddToCartButton({ count, pending, onClick }: { count: number; pending: boolean; onClick: () => void }) {
    return (
        <Button id={ADD_TO_CART_ID} type="button" onClick={onClick} disabled={count === 0 || pending} className="h-11 w-full md:h-10 md:w-auto">
            {pending ? <Loader2 className="animate-spin" /> : <ShoppingCart />}
            {pending ? 'Adding…' : count === 0 ? 'Add to cart' : `Add ${count.toLocaleString('en-GB')} ${count === 1 ? 'line' : 'lines'} to cart`}
        </Button>
    );
}

export function AddToCartResult({ outcome, pending, onDismiss, onAddAcceptable }: { outcome: Outcome; pending: boolean; onDismiss: () => void; onAddAcceptable: () => void }) {
    const dismiss = (
        <Button type="button" variant="ghost" size="icon" className="size-11 shrink-0 md:size-8" onClick={onDismiss} aria-label="Dismiss">
            <X />
        </Button>
    );

    if (outcome.kind === 'added') {
        return (
            <section role="status" className="flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                <CircleCheck className="mt-0.5 size-4 shrink-0" aria-hidden />
                <div className="min-w-0 flex-1">
                    <p className="font-medium">
                        {outcome.lines.length.toLocaleString('en-GB')} {outcome.lines.length === 1 ? 'line' : 'lines'} added to your cart.
                        {outcome.stillRefused > 0 && ` ${outcome.stillRefused} still need attention — they're flagged in the list.`}
                    </p>
                    <ul className="mt-1 max-h-24 overflow-y-auto text-xs">
                        {outcome.lines.map((l) => (
                            <li key={l.skuId} className="truncate">
                                <span className="font-mono">{l.skuCode}</span> {l.productName} — {describe(l)}
                            </li>
                        ))}
                    </ul>
                </div>
                {dismiss}
            </section>
        );
    }

    if (outcome.kind === 'failed') {
        return (
            <section role="alert" className="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">
                <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                <p className="min-w-0 flex-1">Nothing was added: {outcome.message} Your quantities are unchanged — try again.</p>
                {dismiss}
            </section>
        );
    }

    return (
        <section role="alert" className="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">
            <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div className="min-w-0 flex-1">
                <p className="font-medium">
                    Nothing was added — {outcome.refused.length} {outcome.refused.length === 1 ? 'line needs' : 'lines need'} changing first. Your quantities are kept.
                </p>
                <ul className="mt-1 max-h-28 space-y-0.5 overflow-y-auto text-xs">
                    {outcome.refused.map((r) => (
                        <li key={r.line.skuId}>
                            <span className="font-mono">{r.line.skuCode}</span> {r.line.productName} — {describe(r.line)}: <span className="font-medium">{r.message}</span>
                        </li>
                    ))}
                </ul>
                {outcome.acceptable.length > 0 && (
                    <Button type="button" size="sm" variant="outline" className="mt-2 h-11 border-red-300 bg-white md:h-8" disabled={pending} onClick={onAddAcceptable}>
                        Add the other {outcome.acceptable.length.toLocaleString('en-GB')} {outcome.acceptable.length === 1 ? 'line' : 'lines'} now
                    </Button>
                )}
            </div>
            {dismiss}
        </section>
    );
}
