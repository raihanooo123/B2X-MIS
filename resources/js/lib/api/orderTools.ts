/**
 * 05.1 §14 — order-pad tools: stage a paste or CSV, poll a queued import,
 * confirm selected rows into the basket (once), saved lists and reorder.
 * Branch on `ApiError.code`, never the message: `preview_changed`,
 * `cart_changed`, `import_expired` and `list_changed` are 409s that mean
 * "reload and check again".
 */
import { apiRequest } from './client';

export type ImportOutcome = 'ok' | 'adjust' | 'short' | 'not_found' | 'ambiguous' | 'inactive' | 'no_pack' | 'invalid';

export interface ImportRow {
    row_no: number;
    input: string;
    sku_id: string | null;
    sku_code: string | null;
    name: string | null;
    pack_code: string | null;
    pack_label: string | null;
    pack_qty: number | null;
    pack_base_units: number | null;
    base_qty: number | null;
    outcome: ImportOutcome;
    error_code: string | null;
    problem: string;
    suggested_pack_qty: number | null;
    accepted_adjustment: boolean;
    merged_row_nos: number[];
    suggestions: string[];
    status: string | null;
}

export interface ImportDto {
    id: string;
    source: 'paste' | 'csv' | 'saved_list' | 'reorder';
    status: 'pending' | 'processing' | 'ready' | 'failed' | 'confirmed' | 'expired';
    version: number;
    created_at: string;
    expires_at: string;
    row_count: number;
    counts: Partial<Record<ImportOutcome, number>>;
    rows: ImportRow[];
    confirmed: { lines: number; row_nos: number[] } | null;
    url?: string;
}

export const SELECTABLE: ImportOutcome[] = ['ok', 'adjust', 'short'];

/** Paste is JSON; a CSV goes as multipart form data. */
export async function stageImport(input: { source: 'paste'; text: string } | { source: 'csv'; file: File }): Promise<{ data: ImportDto }> {
    if (input.source === 'paste') {
        return apiRequest('/order-imports', { method: 'POST', body: input });
    }
    const form = new FormData();
    form.append('source', 'csv');
    form.append('file', input.file);

    return apiRequest('/order-imports', { method: 'POST', formData: form });
}

export function importStatus(id: string, signal?: AbortSignal): Promise<{ data: ImportDto }> {
    return apiRequest(`/order-imports/${id}`, { signal });
}

export function confirmImport(id: string, body: { version: number; rows: number[]; accept: number[]; cart_version: string }): Promise<{ data: { lines: number; row_nos: number[]; cart_url: string } }> {
    return apiRequest(`/order-imports/${id}/confirm`, { method: 'POST', body });
}

export interface SavedListLine {
    sku_id: string | null;
    sku_code: string;
    name: string;
    sku_status: string;
    pack_code: string;
    pack_label: string;
    pack_sellable: boolean;
    pack_qty: number;
    pack_base_units: number;
    base_qty: number;
}

export interface SavedListSummary {
    id: string;
    name: string;
    version: number;
    source: 'manual' | 'cart' | 'order';
    line_count: number;
    created_by: string | null;
    updated_at: string;
}

export interface SavedListDetail extends SavedListSummary {
    lines: SavedListLine[];
}

export function createSavedList(name: string, fromCart: boolean): Promise<{ data: SavedListDetail & { url: string } }> {
    return apiRequest('/saved-lists', { method: 'POST', body: { name, from_cart: fromCart } });
}

export function updateSavedList(id: string, body: { version: number; name?: string; lines?: { sku_id: string; pack_code: string; pack_qty: number }[] }): Promise<{ data: SavedListDetail }> {
    return apiRequest(`/saved-lists/${id}`, { method: 'PATCH', body });
}

export function deleteSavedList(id: string, version: number): Promise<null> {
    return apiRequest(`/saved-lists/${id}`, { method: 'DELETE', body: { version } });
}

export function previewSavedList(id: string): Promise<{ data: ImportDto }> {
    return apiRequest(`/saved-lists/${id}/preview`, { method: 'POST' });
}

export function reorderPreview(orderId: string): Promise<{ data: ImportDto }> {
    return apiRequest(`/orders/${orderId}/reorder-preview`, { method: 'POST' });
}
