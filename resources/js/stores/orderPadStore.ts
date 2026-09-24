/**
 * Ephemeral order pad UI state (CLAUDE.md: Zustand for client-side UI
 * state): the pack each row has selected and the quantity typed into it,
 * keyed by SKU ULID. Kept outside the row components so the footer
 * (05.1 §5.3) can read every row's draft without prop drilling.
 *
 * `pricing` is the one piece of server data held here, and only as a
 * calculator input: the footer totals every draft, including rows typed
 * on an earlier page whose bulk-resolve result is no longer on screen
 * (and may have been garbage-collected from the query cache). Each page
 * overwrites its own SKUs' entries whenever bulk-resolve answers, so a
 * refetch replaces stale figures. Rows read their price from the query,
 * not from here.
 *
 * Not the cart. Nothing here is persisted — pad state that must survive
 * is the server-side cart (05.1 §8.3), written through lib/api/orderPad.ts.
 */
import { create } from 'zustand';

import type { LinePricing } from '@/lib/pricing/localRecompute';

export interface RowDraft {
    /** null → the row's default pack. */
    packCode: string | null;
    /** In packs. null means empty — never 0 (05.1 §4.2). */
    packQty: number | null;
    /**
     * The selected pack's base units, recorded with the quantity so the
     * footer can convert packs → base units for rows no longer on screen.
     */
    packBaseUnits: number | null;
}

interface OrderPadState {
    drafts: Record<string, RowDraft>;
    /** null: the SKU could not be priced. */
    pricing: Record<string, LinePricing | null>;
    setPack: (skuId: string, packCode: string, packBaseUnits: number) => void;
    setQty: (skuId: string, packQty: number | null, packBaseUnits: number) => void;
    rememberPricing: (bySku: Record<string, LinePricing | null>) => void;
}

const EMPTY: RowDraft = { packCode: null, packQty: null, packBaseUnits: null };

export const useOrderPadStore = create<OrderPadState>((set) => ({
    drafts: {},
    pricing: {},
    setPack: (skuId, packCode, packBaseUnits) =>
        set((state) => ({ drafts: { ...state.drafts, [skuId]: { ...(state.drafts[skuId] ?? EMPTY), packCode, packBaseUnits } } })),
    setQty: (skuId, packQty, packBaseUnits) =>
        set((state) => ({
            drafts: {
                ...state.drafts,
                [skuId]: { ...(state.drafts[skuId] ?? EMPTY), packQty: packQty !== null && packQty > 0 ? packQty : null, packBaseUnits },
            },
        })),
    rememberPricing: (bySku) => set((state) => ({ pricing: { ...state.pricing, ...bySku } })),
}));

export function useRowDraft(skuId: string): RowDraft {
    return useOrderPadStore((state) => state.drafts[skuId] ?? EMPTY);
}
