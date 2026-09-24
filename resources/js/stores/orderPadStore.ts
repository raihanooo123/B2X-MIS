/**
 * Ephemeral order pad UI state (CLAUDE.md: Zustand for client-side UI
 * state): the pack each row has selected and the quantity typed into it,
 * keyed by SKU ULID. Kept outside the row components so the footer
 * (05.1 §5.3) can read every row's draft without prop drilling, and so
 * drafts survive paging, searching and filtering.
 *
 * A draft records what its row showed when it was typed — pack code and
 * base units, SKU code, product name, pack label — so the footer can
 * price it and "Add to cart" can send and describe it after the row has
 * scrolled off the current page or out of the current filter.
 *
 * `pricing` is the one piece of server data held here, and only as a
 * calculator input: the footer totals every draft, including rows typed
 * on an earlier page whose bulk-resolve result is no longer on screen
 * (and may have been garbage-collected from the query cache). Each page
 * overwrites its own SKUs' entries whenever bulk-resolve answers, so a
 * refetch replaces stale figures. Rows read their price from the query,
 * not from here.
 *
 * `rejections` holds the reason bulk-add refused a line, shown on the
 * row until the buyer changes it.
 *
 * Not the cart. Nothing here is persisted — pad state that must survive
 * is the server-side cart (05.1 §8.3), which "Add to cart" writes to.
 */
import { create } from 'zustand';

import type { PadPack } from '@/lib/orderPad/display';
import type { LinePricing } from '@/lib/pricing/localRecompute';

export interface RowIdentity {
    sku_id: string;
    sku_code: string;
    product_name: string;
}

export interface RowDraft {
    /** null → the row's default pack. */
    packCode: string | null;
    /** In packs. null means empty — never 0 (05.1 §4.2). */
    packQty: number | null;
    /** The selected pack's base units, for packs → base units off-screen. */
    packBaseUnits: number | null;
    packLabel: string | null;
    skuCode: string;
    productName: string;
}

interface OrderPadState {
    drafts: Record<string, RowDraft>;
    /** null: the SKU could not be priced. */
    pricing: Record<string, LinePricing | null>;
    rejections: Record<string, string>;
    setPack: (row: RowIdentity, pack: PadPack) => void;
    setQty: (row: RowIdentity, pack: PadPack, packQty: number | null) => void;
    rememberPricing: (bySku: Record<string, LinePricing | null>) => void;
    /** After a successful add: those lines are in the cart now, not on the pad. */
    clearDrafts: (skuIds: readonly string[]) => void;
    setRejections: (bySku: Record<string, string>) => void;
}

const EMPTY: RowDraft = { packCode: null, packQty: null, packBaseUnits: null, packLabel: null, skuCode: '', productName: '' };

function withoutKey<T>(record: Record<string, T>, key: string): Record<string, T> {
    if (!(key in record)) {
        return record;
    }
    const next = { ...record };
    delete next[key];

    return next;
}

function draftFor(state: OrderPadState, row: RowIdentity, pack: PadPack): RowDraft {
    return {
        ...(state.drafts[row.sku_id] ?? EMPTY),
        packCode: pack.code,
        packBaseUnits: pack.base_units,
        packLabel: pack.label,
        skuCode: row.sku_code,
        productName: row.product_name,
    };
}

export const useOrderPadStore = create<OrderPadState>((set) => ({
    drafts: {},
    pricing: {},
    rejections: {},
    setPack: (row, pack) =>
        set((state) => ({
            drafts: { ...state.drafts, [row.sku_id]: draftFor(state, row, pack) },
            rejections: withoutKey(state.rejections, row.sku_id),
        })),
    setQty: (row, pack, packQty) =>
        set((state) => ({
            drafts: { ...state.drafts, [row.sku_id]: { ...draftFor(state, row, pack), packQty: packQty !== null && packQty > 0 ? packQty : null } },
            rejections: withoutKey(state.rejections, row.sku_id),
        })),
    rememberPricing: (bySku) => set((state) => ({ pricing: { ...state.pricing, ...bySku } })),
    clearDrafts: (skuIds) =>
        set((state) => {
            const drafts = { ...state.drafts };
            const rejections = { ...state.rejections };
            for (const id of skuIds) {
                delete drafts[id];
                delete rejections[id];
            }

            return { drafts, rejections };
        }),
    setRejections: (bySku) => set((state) => ({ rejections: { ...state.rejections, ...bySku } })),
}));

export function useRowDraft(skuId: string): RowDraft {
    return useOrderPadStore((state) => state.drafts[skuId] ?? EMPTY);
}

export function useRowRejection(skuId: string): string | undefined {
    return useOrderPadStore((state) => state.rejections[skuId]);
}

/** Drafts with a quantity, in the order they were first touched. */
export function typedLines(drafts: Record<string, RowDraft>): Array<RowDraft & { skuId: string; packQty: number; packCode: string; packBaseUnits: number }> {
    const lines = [];
    for (const [skuId, d] of Object.entries(drafts)) {
        if (d.packQty !== null && d.packCode !== null && d.packBaseUnits !== null) {
            lines.push({ ...d, skuId, packQty: d.packQty, packCode: d.packCode, packBaseUnits: d.packBaseUnits });
        }
    }

    return lines;
}
