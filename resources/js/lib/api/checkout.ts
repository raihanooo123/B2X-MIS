/**
 * Checkout preview and placing the order (06 §9.2–9.3).
 *
 * Preview is side-effect free and re-run whenever the cart or the
 * delivery country changes; its totals are the server's and are shown
 * as-is. Placing the order sends back the total the buyer saw
 * (`expected_total_gross_minor`); if the server now disagrees it answers
 * 409 `price_changed` with both figures and commits nothing.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiRequest, type ApiError } from './client';
import { orderPadKeys, type Ulid } from './orderPad';

export interface PreviewBlocker {
    field: string | null;
    code: string;
    message: string;
    meta: Record<string, unknown>;
}

export interface PreviewLine {
    cart_line_id: Ulid;
    sku_id: Ulid | null;
    base_qty: number;
    priced: boolean;
    unit_price_net_e4: number | null;
    price_source: string | null;
    line_discount_minor: number | null;
    line_spend_discount_minor: number | null;
    line_net_minor: number | null;
    tax_rate_bp: number | null;
    line_tax_minor: number | null;
    line_gross_minor: number | null;
}

export interface CheckoutPreview {
    subtotal_net_minor: number;
    spend_break: { code: string; name: string; discount_minor: number } | null;
    delivery: null;
    tax_minor: number;
    total_gross_minor: number;
    account_credit_applied_minor: number;
    amount_due_minor: number;
    credit: { available_minor: number; sufficient: boolean } | null;
    minimum_order_net_minor: number | null;
    lines: PreviewLine[];
    blockers: PreviewBlocker[];
}

export function useCheckoutPreview(countryCode: string | null, options: { enabled?: boolean } = {}) {
    return useQuery<CheckoutPreview, ApiError>({
        queryKey: orderPadKeys.checkoutPreview(countryCode ?? ''),
        queryFn: ({ signal }) =>
            apiRequest<CheckoutPreview>('/checkout/preview', {
                method: 'POST',
                body: { delivery_country_code: countryCode, fulfilment_type: 'delivery' },
                signal,
            }),
        enabled: (options.enabled ?? true) && countryCode !== null,
        placeholderData: keepPreviousData,
    });
}

export type PaymentMethod = 'card' | 'bacs' | 'on_account';

export interface DeliveryAddressInput {
    contact_name: string;
    company_name: string;
    phone: string;
    line1: string;
    line2: string;
    city: string;
    county: string;
    postcode: string;
    country_code: string;
}

export interface PlaceOrderInput {
    payment_method: PaymentMethod;
    expected_total_gross_minor: number;
    customer_reference: string;
    delivery_address: DeliveryAddressInput;
}

export interface PlacedOrder {
    id: Ulid;
    order_number: string;
    total_gross_minor: number;
    confirmation_url: string;
}

/**
 * The `Idempotency-Key` is the caller's: reuse it when retrying the same
 * body (a lost response replays, never double-orders), use a new one when
 * the body changes (06 §6).
 */
export function usePlaceOrder() {
    const queryClient = useQueryClient();

    return useMutation<PlacedOrder, ApiError, { input: PlaceOrderInput; idempotencyKey: string }>({
        mutationFn: ({ input, idempotencyKey }) =>
            apiRequest<{ data: PlacedOrder }>('/checkout', {
                method: 'POST',
                body: input,
                headers: { 'Idempotency-Key': idempotencyKey },
            }).then((r) => r.data),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: orderPadKeys.cart() });
        },
    });
}
