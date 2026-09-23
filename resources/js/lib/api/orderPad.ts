/**
 * TanStack Query hooks for the order pad (doc 05.1): price resolution,
 * stock availability and the server-side cart (05.1 §8.3 — pad state
 * lives in `carts`/`cart_lines`, never browser storage).
 *
 * Server state only. Ephemeral UI state (focused row, draft quantity)
 * belongs in Zustand, and none of this duplicates Inertia page props.
 *
 * Payload types mirror the API exactly — snake_case, scale suffixes kept
 * (06 §2): `unit_price_net_e4` is ten-thousandths of a pound, never
 * pence. Do arithmetic on them only through lib/money.ts.
 *
 * Every cart mutation answers with the whole cart (an add can merge into
 * an existing line, a pack change can merge two lines), so mutations
 * write that response straight into the cart query instead of
 * refetching. DELETE is 204 and invalidates instead.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest, type ApiError } from './client';

export type Ulid = string;

export type PriceSource = 'contract' | 'customer' | 'promotion' | 'tier' | 'base' | 'manual';

// --- /pricing/bulk-resolve (06 §9.1) ---------------------------------------

export interface PriceBreak {
    min_base_qty: number;
    unit_price_net_e4: number;
}

export interface ResolvedPrice {
    unit_price_net_e4: number;
    price_source: PriceSource;
    applied_break_qty: number;
    tax_rate_bp: number;
    next_break_qty: number | null;
    next_break_unit_price_net_e4: number | null;
}

export interface EntryError {
    code: string;
    message: string;
}

export type BulkResolveEntry =
    | { sku_id: Ulid; resolved: ResolvedPrice; breaks?: PriceBreak[] }
    | { sku_id: Ulid; error: EntryError };

export interface BulkResolveResponse {
    data: BulkResolveEntry[];
}

// --- /stock/availability (06 §9.4) ------------------------------------------

export type StockAvailabilityEntry =
    | {
          sku_id: Ulid;
          available_base_qty: number;
          is_stock_tracked: boolean;
          allow_backorder: boolean;
          incoming: { base_qty: number; expected_on: string | null } | null;
      }
    | { sku_id: Ulid; error: EntryError };

export interface StockAvailabilityResponse {
    data: StockAvailabilityEntry[];
}

// --- /cart (06 §8) ----------------------------------------------------------

export interface CartLine {
    id: Ulid;
    sku: {
        id: Ulid;
        sku_code: string;
        name: string | null;
        variant_label: string | null;
        status: string;
    };
    /** Packs are addressed by `code` within their SKU — they have no ULID. */
    pack: { code: string; label: string; base_units: number };
    pack_qty: number;
    base_qty: number;
    updated_at: string | null;
}

export interface Cart {
    /** null until the first line is added — reading never creates a cart. */
    id: Ulid | null;
    line_count: number;
    lines: CartLine[];
}

interface CartResponse {
    data: Cart;
}

export interface CartItemInput {
    sku_id: Ulid;
    /** Omit for the SKU's default sell pack. */
    pack_code?: string;
    pack_qty: number;
    /** Optional cross-check; must equal pack_qty × base_units (06 §3.2). */
    base_qty?: number;
}

export interface UpdateCartLineInput {
    id: Ulid;
    pack_qty?: number;
    /** A different code is a pack change — an in-place line update (05.1 §8.3). */
    pack_code?: string;
    base_qty?: number;
}

// --- Query keys ---------------------------------------------------------------

/** Sorted so the same set of SKUs in a different order shares a cache entry. */
function sortedIds(skuIds: readonly Ulid[]): Ulid[] {
    return [...new Set(skuIds)].sort();
}

export const orderPadKeys = {
    all: ['order-pad'] as const,
    bulkResolve: (skuIds: readonly Ulid[], baseQty: number, includeBreaks: boolean) =>
        [...orderPadKeys.all, 'bulk-resolve', sortedIds(skuIds), baseQty, includeBreaks] as const,
    stock: (skuIds: readonly Ulid[]) => [...orderPadKeys.all, 'stock', sortedIds(skuIds)] as const,
    cart: () => [...orderPadKeys.all, 'cart'] as const,
};

// --- Queries --------------------------------------------------------------------

export interface BulkResolveOptions {
    /** Defaults to 1 on the server. */
    baseQty?: number;
    /** Full break table, so quantity changes recompute client-side (05.1 §5.1). Default true. */
    includeBreaks?: boolean;
    enabled?: boolean;
}

/**
 * The pad's hot path: prices plus full break tables for one page of SKUs
 * (≤ 100, 05.1 §9). A POST, but a read — safe to cache and retry.
 * Keeps the previous page's data while the next page resolves so rows
 * don't flash empty.
 */
export function useBulkResolve(skuIds: readonly Ulid[], options: BulkResolveOptions = {}) {
    const baseQty = options.baseQty ?? 1;
    const includeBreaks = options.includeBreaks ?? true;

    return useQuery<BulkResolveResponse, ApiError>({
        queryKey: orderPadKeys.bulkResolve(skuIds, baseQty, includeBreaks),
        queryFn: ({ signal }) =>
            apiRequest<BulkResolveResponse>('/pricing/bulk-resolve', {
                method: 'POST',
                body: { sku_ids: sortedIds(skuIds), base_qty: baseQty, include_breaks: includeBreaks },
                signal,
            }),
        enabled: (options.enabled ?? true) && skuIds.length > 0,
        placeholderData: keepPreviousData,
    });
}

export function useStockAvailability(skuIds: readonly Ulid[], options: { enabled?: boolean } = {}) {
    return useQuery<StockAvailabilityResponse, ApiError>({
        queryKey: orderPadKeys.stock(skuIds),
        queryFn: ({ signal }) =>
            apiRequest<StockAvailabilityResponse>('/stock/availability', {
                query: { sku_ids: sortedIds(skuIds) },
                signal,
            }),
        enabled: (options.enabled ?? true) && skuIds.length > 0,
        placeholderData: keepPreviousData,
    });
}

export function useCart() {
    return useQuery<Cart, ApiError>({
        queryKey: orderPadKeys.cart(),
        queryFn: ({ signal }) => apiRequest<CartResponse>('/cart', { signal }).then((r) => r.data),
    });
}

// --- Mutations ------------------------------------------------------------------

function useCartMutation<TInput>(request: (input: TInput) => Promise<CartResponse>) {
    const queryClient = useQueryClient();

    return useMutation<Cart, ApiError, TInput>({
        mutationFn: (input) => request(input).then((r) => r.data),
        onSuccess: (cart) => {
            queryClient.setQueryData(orderPadKeys.cart(), cart);
        },
    });
}

/**
 * On a 422 the ApiError's `details[]` names the rejected field and a
 * stable code (`not_purchasable`, `pack_not_found`, `base_qty_mismatch`…).
 */
export function useAddCartLine() {
    return useCartMutation<CartItemInput>((input) =>
        apiRequest<CartResponse>('/cart/lines', { method: 'POST', body: input }),
    );
}

export function useUpdateCartLine() {
    return useCartMutation<UpdateCartLineInput>(({ id, ...changes }) =>
        apiRequest<CartResponse>(`/cart/lines/${encodeURIComponent(id)}`, { method: 'PATCH', body: changes }),
    );
}

/**
 * All-or-nothing, ≤ 500 lines. On a 422 each `details[]` entry carries
 * `meta.line_index` (0-based) and a reason code, so the reconciliation
 * screen can mark exactly which pasted lines failed (05.1 §7).
 */
export function useBulkAddToCart() {
    return useCartMutation<CartItemInput[]>((lines) =>
        apiRequest<CartResponse>('/cart/bulk-add', { method: 'POST', body: { lines } }),
    );
}

export function useRemoveCartLine() {
    const queryClient = useQueryClient();

    return useMutation<null, ApiError, Ulid>({
        mutationFn: (id) => apiRequest<null>(`/cart/lines/${encodeURIComponent(id)}`, { method: 'DELETE' }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: orderPadKeys.cart() }),
    });
}
