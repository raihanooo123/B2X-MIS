/**
 * Ephemeral order pad UI state (CLAUDE.md: Zustand for client-side UI
 * state): the pack each row has selected and the quantity typed into it,
 * keyed by SKU ULID. Kept outside the row components so the footer
 * (05.1 §5.3, next) can read every row's draft without prop drilling.
 *
 * Not the cart. Nothing here is persisted — pad state that must survive
 * is the server-side cart (05.1 §8.3), written through lib/api/orderPad.ts.
 */
import { create } from 'zustand';

export interface RowDraft {
    /** null → the row's default pack. */
    packCode: string | null;
    /** In packs. null means empty — never 0 (05.1 §4.2). */
    packQty: number | null;
}

interface OrderPadState {
    drafts: Record<string, RowDraft>;
    setPack: (skuId: string, packCode: string) => void;
    setQty: (skuId: string, packQty: number | null) => void;
}

const EMPTY: RowDraft = { packCode: null, packQty: null };

export const useOrderPadStore = create<OrderPadState>((set) => ({
    drafts: {},
    setPack: (skuId, packCode) =>
        set((state) => ({ drafts: { ...state.drafts, [skuId]: { ...(state.drafts[skuId] ?? EMPTY), packCode } } })),
    setQty: (skuId, packQty) =>
        set((state) => ({
            drafts: { ...state.drafts, [skuId]: { ...(state.drafts[skuId] ?? EMPTY), packQty: packQty !== null && packQty > 0 ? packQty : null } },
        })),
}));

export function useRowDraft(skuId: string): RowDraft {
    return useOrderPadStore((state) => state.drafts[skuId] ?? EMPTY);
}
