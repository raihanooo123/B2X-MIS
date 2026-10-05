/**
 * 05.2 §18.3 — trade approvals, company users and paying an approved
 * order (06 §18). Decisions and payments carry an Idempotency-Key, one per
 * user action: a retried click replays the same answer, never a second
 * decision. Branch on `ApiError.code`, never on the message.
 */
import { apiRequest } from './client';

export interface DecisionResult {
    id: string;
    status: 'approved' | 'rejected' | 'expired' | 'pending';
    decided_at: string | null;
    order: { id: string | null; status: string | null };
}

export interface BulkResult {
    id: string;
    ok: boolean;
    code: string | null;
    message: string | null;
}

const idempotent = (key: string) => ({ 'Idempotency-Key': key });

export function decideApproval(id: string, decision: 'approve' | 'reject', key: string, reason?: string): Promise<{ data: DecisionResult }> {
    return apiRequest(`/approvals/${id}/${decision}`, { method: 'POST', body: { expected_status: 'pending', ...(reason ? { reason } : {}) }, headers: idempotent(key) });
}

export function bulkRejectApprovals(ids: string[], reason: string, key: string): Promise<{ data: BulkResult[] }> {
    return apiRequest('/approvals/reject', { method: 'POST', body: { ids, reason }, headers: idempotent(key) });
}

export interface MemberSettings {
    role: 'owner' | 'buyer' | 'approver' | 'viewer';
    /** Pounds as typed, e.g. "2500.50"; empty for no limit. Converted once on the server. */
    order_limit: string;
    requires_approval: boolean;
}

export function updateCompanyUser(userId: string, settings: MemberSettings): Promise<{ data: unknown }> {
    return apiRequest(`/company-users/${userId}`, { method: 'PATCH', body: { ...settings, order_limit: settings.order_limit.trim() === '' ? null : settings.order_limit.trim() } });
}

export function payInAdvance(orderId: string, key: string): Promise<{ data: { id: string; status: string; payment_method: string } }> {
    return apiRequest(`/orders/${orderId}/pay-in-advance`, { method: 'POST', headers: idempotent(key) });
}

export function approvedOrderCardIntent(orderId: string): Promise<{ data: { id: string; client_secret: string | null; status: string; amount_minor: number } }> {
    return apiRequest(`/orders/${orderId}/card-intent`, { method: 'POST' });
}

export function payApprovedOrder(orderId: string, paymentIntentId: string, key: string): Promise<{ data: { id: string; status: string; payment_status: string; confirmation_url: string } }> {
    return apiRequest(`/orders/${orderId}/pay`, { method: 'POST', body: { payment_intent_id: paymentIntentId }, headers: idempotent(key) });
}
