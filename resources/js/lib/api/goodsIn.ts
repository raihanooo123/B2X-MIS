/**
 * TanStack Query hooks for goods-in (05.5 §4, 06 §8 `/warehouse/*`).
 *
 * Payload types mirror the API exactly — snake_case, `_base_qty` suffixes
 * kept (06 §2). No cost figure is ever returned (CLAUDE.md invariant 9);
 * `costed` only says whether a receipt line carried one.
 *
 * Receiving a line needs an `Idempotency-Key` (06 §6, 05.5 §10). The
 * caller owns it: reuse it to retry the same entry after a dropped
 * response — the server answers with the original booking — and take a
 * new one when the entry changes. The key becomes the line's
 * `client_token`, so the guarantee outlives the 24-hour response cache.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest, type ApiError } from './client';

export type Ulid = string;

export type TrackingMode = 'none' | 'batch' | 'serial' | 'batch_and_serial';

export type ReceiptSource = 'purchase_order' | 'container' | 'manual';

export interface ReceivingPack {
    code: string;
    label: string;
    base_units: number;
    barcode: string | null;
}

export interface ReceivingSku {
    id: Ulid;
    sku_code: string;
    name: string | null;
    barcode: string | null;
    tracking_mode: TrackingMode;
    requires_expiry: boolean;
    /** Today plus `shelf_life_days` (05.5 §4.3), Y-m-d, or null. */
    expiry_prefill: string | null;
    packs: ReceivingPack[];
}

export interface ExpectedLine {
    po_number: string;
    line_no: number;
    sku: ReceivingSku | null;
    pack_code: string | null;
    pack_label: string | null;
    pack_base_units: number;
    ordered_pack_qty: number;
    ordered_base_qty: number;
    received_base_qty: number;
    outstanding_base_qty: number;
    variance_reason: string | null;
}

export interface ReceivedLine {
    key: string;
    received_at: string;
    po_number: string | null;
    line_no: number | null;
    sku_code: string | null;
    name: string | null;
    pack_label: string | null;
    pack_qty: number;
    base_qty: number;
    batch_code: string | null;
    expires_on: string | null;
    bin_code: string | null;
    serial_count: number;
    costed: boolean;
}

export interface GoodsReceipt {
    id: Ulid;
    source: ReceiptSource;
    status: 'open' | 'closed';
    reference: string | null;
    location: { code: string | null; name: string | null };
    opened_at: string;
    closed_at: string | null;
    expected_lines: ExpectedLine[];
    lines: ReceivedLine[];
}

export const goodsInKeys = {
    receipt: (id: Ulid) => ['warehouse', 'receipt', id] as const,
};

export function useReceipt(id: Ulid | null, initial: GoodsReceipt | null) {
    return useQuery<GoodsReceipt, ApiError>({
        queryKey: goodsInKeys.receipt(id ?? 'none'),
        queryFn: () => apiRequest<{ data: GoodsReceipt }>(`/warehouse/receipts/${id}`).then((r) => r.data),
        enabled: id !== null,
        initialData: initial !== null && initial.id === id ? initial : undefined,
    });
}

export interface OpenReceiptInput {
    source: ReceiptSource;
    reference?: string;
    location_code?: string;
}

export function useOpenReceipt() {
    const queryClient = useQueryClient();

    return useMutation<GoodsReceipt, ApiError, OpenReceiptInput>({
        mutationFn: (input) => apiRequest<{ data: GoodsReceipt }>('/warehouse/receipts', { method: 'POST', body: input }).then((r) => r.data),
        onSuccess: (receipt) => queryClient.setQueryData(goodsInKeys.receipt(receipt.id), receipt),
    });
}

export interface ReceiveLineInput {
    purchase_order_line: { po_number: string; line_no: number } | null;
    sku_id: Ulid;
    pack_code: string;
    pack_qty: number;
    batch_code?: string | null;
    expires_on?: string | null;
    serials?: string[];
    bin_code?: string | null;
    unit_cost_e4?: number | null;
    confirm_expiry?: boolean;
}

export interface ReceivedResult {
    replayed: boolean;
    base_qty: number;
    receipt: GoodsReceipt;
}

export function useReceiveLine(receiptId: Ulid) {
    const queryClient = useQueryClient();

    return useMutation<ReceivedResult, ApiError, { input: ReceiveLineInput; idempotencyKey: string }>({
        mutationFn: ({ input, idempotencyKey }) =>
            apiRequest<{ data: ReceivedResult }>(`/warehouse/receipts/${receiptId}/lines`, {
                method: 'POST',
                body: input,
                headers: { 'Idempotency-Key': idempotencyKey },
            }).then((r) => r.data),
        onSuccess: (result) => queryClient.setQueryData(goodsInKeys.receipt(receiptId), result.receipt),
    });
}

export interface VarianceInput {
    po_number: string;
    line_no: number;
    reason: string | null;
    remainder_expected: boolean;
}

export function useCloseReceipt(receiptId: Ulid) {
    const queryClient = useQueryClient();

    return useMutation<GoodsReceipt, ApiError, VarianceInput[]>({
        mutationFn: (variances) =>
            apiRequest<{ data: GoodsReceipt }>(`/warehouse/receipts/${receiptId}/close`, {
                method: 'POST',
                body: { variances: variances.map((v) => (v.remainder_expected ? { po_number: v.po_number, line_no: v.line_no, remainder_expected: true } : { po_number: v.po_number, line_no: v.line_no, reason: v.reason })) },
            }).then((r) => r.data),
        onSuccess: (receipt) => queryClient.setQueryData(goodsInKeys.receipt(receiptId), receipt),
    });
}

export type LookupResult =
    | { kind: 'purchase_order'; reference: string; status: string; horizon_days?: number }
    | { kind: 'container'; reference: string; status: string; horizon_days?: number }
    | { kind: 'sku'; sku: ReceivingSku; pack_code: string | null; horizon_days?: number }
    | { kind: 'bin'; bin_code: string; horizon_days?: number }
    | { kind: 'unknown'; code: string; candidates: ReceivingSku[]; horizon_days?: number };

/** 05.5 §9: resolve one scanned or typed code. Unknown codes return search candidates. */
export function lookupCode(code: string, receiptId: Ulid | null): Promise<LookupResult> {
    return apiRequest<{ data: LookupResult }>('/warehouse/lookup', { query: { code, receipt: receiptId ?? undefined } }).then((r) => r.data);
}
