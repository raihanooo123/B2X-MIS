/**
 * TanStack Query hooks for stocktake (05.5 §8, 02 §24; 06 §8
 * `/warehouse/stocktakes*`).
 *
 * Every action answers with the whole stocktake. Counting needs no
 * Idempotency-Key: a count is set, not added, and a serial scan is unique
 * per line, so a retry changes nothing. Posting is idempotent on the
 * stocktake itself.
 *
 * Blind counting: while a blind stocktake is open the server sends no
 * system figure at all — `system_base_qty` is simply absent. In review,
 * each line carries `review`: the level as it stood when counted, the
 * variance against it, and missing / found serials by number.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest, type ApiError } from './client';

export type Ulid = string;

export interface StocktakeLineData {
    line_id: number;
    sku_id: Ulid | null;
    sku_code: string | null;
    name: string | null;
    tracking_mode: 'none' | 'batch' | 'serial' | 'batch_and_serial' | null;
    batch_code: string | null;
    expires_on: string | null;
    counted_base_qty: number;
    counted_at: string;
    serials: string[];
    system_base_qty?: number;
    review?: {
        on_hand_now: number;
        expected_at_count: number;
        variance_base_qty: number;
        missing_serials: string[];
        found_serials: string[];
        blockers: string[];
    };
    posted?: { expected_base_qty: number | null; variance_base_qty: number | null; reason_code: string | null };
}

export interface StocktakeData {
    id: Ulid;
    status: 'open' | 'review' | 'posted' | 'cancelled';
    is_blind: boolean;
    location: { code: string | null; name: string | null };
    started_at: string;
    posted_at: string | null;
    lines: StocktakeLineData[];
}

export const stocktakeKeys = {
    stocktake: (id: Ulid) => ['warehouse', 'stocktake', id] as const,
};

export function useStocktake(id: Ulid | null, initial: StocktakeData | null) {
    return useQuery<StocktakeData, ApiError>({
        queryKey: stocktakeKeys.stocktake(id ?? 'none'),
        queryFn: () => apiRequest<{ data: StocktakeData }>(`/warehouse/stocktakes/${id}`).then((r) => r.data),
        enabled: id !== null,
        initialData: initial !== null && initial.id === id ? initial : undefined,
    });
}

export function useStartStocktake() {
    const queryClient = useQueryClient();

    return useMutation<StocktakeData, ApiError, { location_code: string; blind: boolean }>({
        mutationFn: (input) => apiRequest<{ data: StocktakeData }>('/warehouse/stocktakes', { method: 'POST', body: input }).then((r) => r.data),
        onSuccess: (data) => queryClient.setQueryData(stocktakeKeys.stocktake(data.id), data),
    });
}

function useStocktakeAction<TInput>(id: Ulid, path: string) {
    const queryClient = useQueryClient();

    return useMutation<StocktakeData, ApiError, TInput>({
        mutationFn: (input) => apiRequest<{ data: StocktakeData }>(`/warehouse/stocktakes/${id}/${path}`, { method: 'POST', body: input ?? {} }).then((r) => r.data),
        onSuccess: (data) => queryClient.setQueryData(stocktakeKeys.stocktake(id), data),
    });
}

export interface Identity {
    sku_id: Ulid;
    batch_code: string | null;
}

export const useCountLine = (id: Ulid) => useStocktakeAction<Identity & { pack_code: string; pack_qty: number; loose_units: number }>(id, 'lines');
export const useScanStocktakeSerial = (id: Ulid) => useStocktakeAction<Identity & { serial_number: string }>(id, 'serials');
export const useRemoveStocktakeSerial = (id: Ulid) => useStocktakeAction<Identity & { serial_number: string }>(id, 'serials/remove');
export const useSubmitForReview = (id: Ulid) => useStocktakeAction<void>(id, 'review');
export const useReopenStocktake = (id: Ulid) => useStocktakeAction<void>(id, 'reopen');
export const useCancelStocktake = (id: Ulid) => useStocktakeAction<void>(id, 'cancel');
export const usePostStocktake = (id: Ulid) => useStocktakeAction<{ reasons: (Identity & { reason: string })[] }>(id, 'post');
