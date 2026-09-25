/**
 * TanStack Query hooks for picking and dispatch (05.5 §5–7, 06 §8
 * `/warehouse/shipments*`).
 *
 * Every action answers with the whole refreshed pick list — a short pick
 * can re-plan a line, a substitution moves one to another batch — so
 * mutations write that response straight into the query. No price or
 * cost is ever returned (CLAUDE.md invariant 9).
 *
 * A pick line is addressed by `line_no` and `batch_code`: allocations
 * have no public id, and that pair is their identity within a shipment.
 *
 * Dispatch needs an `Idempotency-Key` (06 §6): reuse it to retry after a
 * dropped response, and the server answers with the dispatched shipment
 * rather than dispatching twice. Serial scans need none: a repeated scan
 * of a picked serial is answered, not repeated (05.5 §10).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest, type ApiError } from './client';

export type Ulid = string;

export interface PickSerial {
    serial_number: string;
    picked: boolean;
}

export interface Substitute {
    batch_code: string;
    expires_on: string | null;
    available_base_qty: number;
}

export interface PickLineData {
    line_no: number;
    sku_code: string;
    name: string;
    thumbnail_url: string | null;
    barcodes: string[];
    pack_label: string;
    pack_base_units: number;
    base_qty: number;
    packs: number;
    loose_units: number;
    tracking_mode: 'none' | 'batch' | 'serial' | 'batch_and_serial';
    batch_code: string | null;
    expires_on: string | null;
    bin_code: string | null;
    status: 'allocated' | 'picked';
    serials: PickSerial[];
    substitutes: Substitute[];
}

export interface OrderLineProgress {
    line_no: number;
    sku_code: string;
    name: string;
    pack_label: string;
    pack_base_units: number;
    ordered_base_qty: number;
    dispatched_base_qty: number;
    on_this_shipment_base_qty: number;
}

export interface PickListData {
    shipment: {
        id: Ulid;
        status: 'pending' | 'picking' | 'picked' | 'packed' | 'dispatched' | 'cancelled';
        fulfilment_type: 'delivery' | 'collection' | 'dropship';
        location_code: string | null;
        carrier: string | null;
        tracking_number: string | null;
        parcel_count: number | null;
        dispatched_at: string | null;
    };
    order: { order_number: string; status: string; customer: string | null; customer_reference: string | null };
    complete: boolean;
    lines: PickLineData[];
    order_lines: OrderLineProgress[];
    result?: Record<string, unknown>;
}

export const fulfilmentKeys = {
    shipment: (id: Ulid) => ['warehouse', 'shipment', id] as const,
};

export function usePickList(id: Ulid | null, initial: PickListData | null) {
    return useQuery<PickListData, ApiError>({
        queryKey: fulfilmentKeys.shipment(id ?? 'none'),
        queryFn: () => apiRequest<{ data: PickListData }>(`/warehouse/shipments/${id}`).then((r) => r.data),
        enabled: id !== null,
        initialData: initial !== null && initial.shipment.id === id ? initial : undefined,
    });
}

export function useOpenShipment() {
    const queryClient = useQueryClient();

    return useMutation<PickListData, ApiError, { order_number: string; location_code?: string }>({
        mutationFn: (input) => apiRequest<{ data: PickListData }>('/warehouse/shipments', { method: 'POST', body: input }).then((r) => r.data),
        onSuccess: (data) => queryClient.setQueryData(fulfilmentKeys.shipment(data.shipment.id), data),
    });
}

export interface LineRef {
    line_no: number;
    batch_code: string | null;
}

/** One mutation per shipment action; each returns the refreshed pick list. */
function useShipmentAction<TInput>(shipmentId: Ulid, path: string) {
    const queryClient = useQueryClient();

    return useMutation<PickListData, ApiError, TInput>({
        mutationFn: (input) => apiRequest<{ data: PickListData }>(`/warehouse/shipments/${shipmentId}/${path}`, { method: 'POST', body: input }).then((r) => r.data),
        onSuccess: (data) => queryClient.setQueryData(fulfilmentKeys.shipment(shipmentId), data),
    });
}

export const useScanSerial = (shipmentId: Ulid) => useShipmentAction<{ serial_number: string }>(shipmentId, 'serial-scans');
export const useConfirmPick = (shipmentId: Ulid) => useShipmentAction<LineRef>(shipmentId, 'picks');
export const useShortPick = (shipmentId: Ulid) =>
    useShipmentAction<LineRef & { picked_pack_qty: number; picked_loose_units: number; reason: string }>(shipmentId, 'short-picks');
export const useSubstituteBatch = (shipmentId: Ulid) =>
    useShipmentAction<LineRef & { batch_code: string; new_batch_code: string; reason: string }>(shipmentId, 'substitutions');

export interface DispatchInput {
    carrier: string | null;
    tracking_number: string | null;
    parcel_count: number | null;
    total_weight_g: number | null;
    note: string | null;
}

export function useDispatchShipment(shipmentId: Ulid) {
    const queryClient = useQueryClient();

    return useMutation<PickListData, ApiError, { input: DispatchInput; idempotencyKey: string }>({
        mutationFn: ({ input, idempotencyKey }) =>
            apiRequest<{ data: PickListData }>(`/warehouse/shipments/${shipmentId}/dispatch`, {
                method: 'POST',
                body: input,
                headers: { 'Idempotency-Key': idempotencyKey },
            }).then((r) => r.data),
        onSuccess: (data) => queryClient.setQueryData(fulfilmentKeys.shipment(shipmentId), data),
    });
}

/** The pick line a scanned SKU code, SKU barcode or case barcode belongs to — first unpicked one first. */
export function lineForCode(lines: PickLineData[], code: string): PickLineData | null {
    const matches = lines.filter((l) => l.barcodes.includes(code));

    return matches.find((l) => l.status === 'allocated') ?? matches[0] ?? null;
}

/** The pick line reserving a scanned serial, if any. */
export function lineForSerial(lines: PickLineData[], serial: string): PickLineData | null {
    return lines.find((l) => l.serials.some((s) => s.serial_number === serial)) ?? null;
}
