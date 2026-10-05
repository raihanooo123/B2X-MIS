/**
 * Shared labels for 05.2 §18 approvals and credit: what each status means
 * in words, and the badge tone that goes with it (text first, colour second).
 */
import type { StatusTone } from '@/components/trade/StatusBadge';

export type ApprovalKind = 'buyer_limit' | 'credit_exception';
export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'expired';

export interface ApprovalRow {
    id: string;
    kind: ApprovalKind;
    status: ApprovalStatus;
    order_number: string;
    order_status: string | null;
    payment_method: string | null;
    buyer_name: string;
    gross_minor: number;
    requested_at: string;
    expires_at: string;
    decided_at: string | null;
    decided_by: string | null;
    decision_reason: string | null;
    can_decide: boolean;
}

export const APPROVAL_STATUS: Record<ApprovalStatus, { label: string; tone: StatusTone }> = {
    pending: { label: 'Waiting', tone: 'pending' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
    expired: { label: 'Expired', tone: 'neutral' },
};

export const APPROVAL_KIND: Record<ApprovalKind, string> = {
    buyer_limit: 'Over buyer limit',
    credit_exception: 'Over available credit — accounts decides',
};

export const PAYMENT_METHOD: Record<string, string> = {
    card: 'Card, after approval',
    bacs: 'Bank transfer',
    on_account: 'On account',
    cash_at_collection: 'Cash at collection',
    prepay: 'Prepaid',
};

export const MOVEMENT_TYPE: Record<string, string> = {
    credit_note: 'Credit note',
    applied_to_invoice: 'Applied to invoice',
    applied_to_order: 'Applied to order',
    payout_reserved: 'Payout reserved',
    refunded_to_bank: 'Refunded to bank',
    refunded_to_card: 'Refunded to card',
    adjustment: 'Adjustment',
    expiry: 'Expired',
    reversal: 'Reversal',
};

/** Hours left before an instant, rounded down; negative once passed. */
export function hoursUntil(iso: string, now: number = Date.now()): number {
    return Math.floor((new Date(iso).getTime() - now) / 3_600_000);
}
