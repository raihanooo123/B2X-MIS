/**
 * 05.17 — shared types and labels for the trade self-service pages:
 * orders, invoices, credit notes and statements. Status always carries
 * text and a tone (text first, colour second).
 */
import type { StatusTone } from '@/components/trade/StatusBadge';
import type { DocumentState } from '@/lib/api/documents';

export interface OrderRow {
    id: string;
    order_number: string;
    customer_reference: string | null;
    status: string;
    status_label: string;
    placed_at: string | null;
    placed_by: string | null;
    payment_status: string;
    total_gross_minor: number;
}

export interface InvoiceRow {
    id: string;
    number: string;
    status: string;
    issued_at: string;
    due_at: string | null;
    overdue: boolean;
    total_gross_minor: number;
    paid_minor: number;
    credited_minor: number;
    outstanding_minor: number;
}

export interface CreditNoteRow {
    id: string;
    number: string;
    status: string;
    reason: string;
    reason_label: string;
    issued_at: string;
    invoice_number: string | null;
    total_gross_minor: number;
    allocated_minor: number;
    to_balance_minor: number;
}

export interface StatementRow {
    id: string;
    from_on: string;
    to_on: string;
    cutoff_at: string;
    requested_at: string;
    requested_by: string | null;
    document_status?: DocumentState['status'];
}

/** Keeps waiting-for-payment, waiting-for-approval, in progress and sent visibly apart. */
export function orderTone(status: string): StatusTone {
    switch (status) {
        case 'awaiting_approval':
            return 'pending';
        case 'pending_payment':
            return 'warning';
        case 'dispatched':
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        case 'confirmed':
        case 'picking':
        case 'part_dispatched':
            return 'info';
        default:
            return 'neutral';
    }
}

export const INVOICE_STATUS: Record<string, { label: string; tone: StatusTone }> = {
    issued: { label: 'Unpaid', tone: 'info' },
    part_paid: { label: 'Part paid', tone: 'warning' },
    overdue: { label: 'Overdue', tone: 'danger' },
    paid: { label: 'Paid', tone: 'success' },
    credited: { label: 'Credited', tone: 'neutral' },
    void: { label: 'Void', tone: 'neutral' },
};

export function invoiceBadge(row: Pick<InvoiceRow, 'status' | 'overdue'>): { label: string; tone: StatusTone } {
    if (row.overdue) {
        return INVOICE_STATUS.overdue;
    }

    return INVOICE_STATUS[row.status] ?? { label: row.status, tone: 'neutral' };
}

export const ORDER_STATUS_FILTERS = [
    { value: '', label: 'All statuses' },
    { value: 'awaiting_approval', label: 'Awaiting approval' },
    { value: 'pending_payment', label: 'Awaiting payment' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'dispatched', label: 'Dispatched' },
    { value: 'cancelled', label: 'Cancelled' },
];

export const INVOICE_STATUS_FILTERS = [
    { value: '', label: 'All invoices' },
    { value: 'unpaid', label: 'Unpaid' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'credited', label: 'Credited' },
    { value: 'void', label: 'Void' },
];

export const DATE_SORTS = {
    orders: [
        { value: 'placed_desc', label: 'Newest first' },
        { value: 'placed_asc', label: 'Oldest first' },
    ],
    documents: [
        { value: 'issued_desc', label: 'Newest first' },
        { value: 'issued_asc', label: 'Oldest first' },
    ],
};

/** A UK calendar day chosen in a filter, shown as a date (noon UTC avoids a zone edge). */
export function dayIso(day: string): string {
    return `${day}T12:00:00Z`;
}
