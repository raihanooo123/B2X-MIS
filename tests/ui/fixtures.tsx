/**
 * Fixed props for each 05.16 §6 screenshot fixture: populated, empty,
 * loading, error and success, for every Module 1 screen. Shapes mirror
 * the controllers (App\Http\Controllers\Trade\*); integer pence, UTC
 * ISO timestamps, as the server sends them.
 */
import type { ComponentType } from 'react';

import { PageHeader } from '@/components/trade/PageHeader';
import { ErrorState, SummarySkeleton, TableSkeleton } from '@/components/trade/states';
import { TradeShell } from '@/components/trade/TradeShell';
import type { Toast } from '@/stores/toastStore';

export const NOW = '2026-10-05T10:00:00Z';

export const sharedProps = {
    auth: {
        user: { first_name: 'Priya', last_name: 'Shah', email: 'priya@harbourstreetfoods.co.uk', email_verified: true, two_factor_enabled: true },
        company: { id: '01J9COMPANY0000000000000000', name: 'Harbour Street Foods Ltd' },
        can_switch_company: true,
        public_customer: false,
        can_manage_team: true,
        trade_navigation: { approvals: true, credit: true, users: true, pending_approvals: 3 },
        staff_navigation: { admin: false, goods_in: false, picking: false, dispatch: false, collections: false, stocktake: false, returns: false },
    },
    flash: { status: null },
    display_timezone: 'Europe/London',
    brand: {
        name: 'Northgate Wholesale', tagline: null, logo_url: null, primary_hsl: null, primary_foreground_hsl: null,
        support_email: 'accounts@northgate.example', support_phone: null, show_powered_by: false,
        legal: { name: 'Northgate Wholesale Ltd', address: [], company_number: null, vat_number: null },
    },
    price_display: { mode: 'net', can_switch: false },
};

const company = { name: 'Harbour Street Foods Ltd', account_code: 'HSF-1042' };

const approvalRow = (i: number, overrides: Record<string, unknown> = {}) => ({
    id: `01J9APPROVAL${String(i).padStart(14, '0')}`,
    kind: 'buyer_limit',
    status: 'pending',
    order_number: `SO-0${10420 + i}`,
    order_status: 'awaiting_approval',
    payment_method: 'on_account',
    buyer_name: ['Tom Okafor', 'Ella Brennan', 'Marcus Lee', 'Aisha Rahman'][i % 4],
    gross_minor: [184_250, 96_600, 1_204_380, 45_720, 310_000, 72_150][i % 6],
    requested_at: new Date(new Date(NOW).getTime() - (i + 1) * 3_600_000 * 5).toISOString(),
    expires_at: new Date(new Date(NOW).getTime() + (43 - i * 7) * 3_600_000).toISOString(),
    decided_at: null,
    decided_by: null,
    decision_reason: null,
    can_decide: true,
    ...overrides,
});

const approvalsProps = (rows: unknown[], filters: Record<string, unknown> = {}) => ({
    company,
    filters: { status: 'pending', buyer: null, from: null, to: null, q: null, sort: 'requested_desc', ...filters },
    buyers: [{ id: '01J9BUYER1', name: 'Aisha Rahman' }, { id: '01J9BUYER2', name: 'Ella Brennan' }, { id: '01J9BUYER3', name: 'Marcus Lee' }, { id: '01J9BUYER4', name: 'Tom Okafor' }],
    pending_count: rows.length,
    rows,
    next_cursor: rows.length >= 6 ? 'eyJmaXh0dXJlIjp0cnVlfQ' : null,
});

const detail = (overrides: Record<string, unknown> = {}) => ({
    ...approvalRow(0),
    company,
    buyer: { name: 'Tom Okafor', email: 'tom.okafor@harbourstreetfoods.co.uk', role: 'buyer', order_limit_minor: 150_000, requires_approval: false },
    order: {
        id: '01J9ORDER000000000000000000',
        order_number: 'SO-010420',
        status: 'awaiting_approval',
        customer_reference: 'PO-77812 Kitchen restock',
        payment_method: 'on_account',
        fulfilment_type: 'delivery',
        placed_at: new Date(new Date(NOW).getTime() - 5 * 3_600_000).toISOString(),
        subtotal_net_minor: 149_458,
        shipping_net_minor: 4_083,
        tax_minor: 30_709,
        total_gross_minor: 184_250,
        delivery: ['Tom Okafor', 'Harbour Street Foods Ltd', 'Unit 4, Quayside Trading Estate', 'Bristol', 'BS1 6XN'],
        lines: [
            { sku_code: 'BAS-RICE-10KG', name: 'Basmati rice, extra long grain, 10 kg sack', pack: 'Sack of 1', pack_qty: 40, base_qty: 40, unit_price_net_e4: 182_500, line_net_minor: 73_000, line_tax_minor: 0, line_gross_minor: 73_000 },
            { sku_code: 'OIL-RAP-20L', name: 'Rapeseed oil 20 L bag-in-box', pack: 'Case of 1', pack_qty: 18, base_qty: 18, unit_price_net_e4: 312_500, line_net_minor: 56_250, line_tax_minor: 11_250, line_gross_minor: 67_500 },
            { sku_code: 'CUP-12OZ-DW', name: 'Double-wall paper cup 12 oz, white', pack: 'Case of 500', pack_qty: 6, base_qty: 3000, unit_price_net_e4: 673, line_net_minor: 20_208, line_tax_minor: 4_042, line_gross_minor: 24_250 },
        ],
    },
    stock_reserved: true,
    requests: [{ kind: 'buyer_limit', status: 'pending', decided_by: null, decided_at: null, reason: null }],
    ...overrides,
});

const creditSummary = (overrides: Record<string, unknown> = {}) => ({
    status: 'approved',
    suspension_reason: null,
    payment_terms: 'net30',
    limit_minor: 2_500_000,
    used_minor: 1_684_220,
    held_minor: 312_600,
    available_minor: 503_180,
    over_limit_minor: 0,
    balance_minor: 12_450,
    on_account: { allowed: true, code: null, message: null },
    overdue: { count: 0, amount_minor: 0, oldest_due_at: null },
    ageing: [
        { bucket: 'current', label: 'Not yet due', count: 7, amount_minor: 1_504_220 },
        { bucket: 'days_1_30', label: '1–30 days overdue', count: 0, amount_minor: 0 },
        { bucket: 'days_31_60', label: '31–60 days overdue', count: 0, amount_minor: 0 },
        { bucket: 'days_61_90', label: '61–90 days overdue', count: 0, amount_minor: 0 },
        { bucket: 'days_90_plus', label: 'Over 90 days overdue', count: 0, amount_minor: 0 },
    ],
    ...overrides,
});

const invoice = (i: number, daysOverdue = 0) => ({
    id: `01J9INVOICE${String(i).padStart(15, '0')}`,
    invoice_number: `INV-0${48810 + i}`,
    issued_at: new Date(new Date(NOW).getTime() - (40 + i) * 86_400_000).toISOString(),
    due_at: new Date(new Date(NOW).getTime() - (daysOverdue - i * 3) * 86_400_000).toISOString(),
    days_overdue: Math.max(0, daysOverdue - i * 3),
    total_gross_minor: [214_880, 96_300, 451_620, 128_000, 309_420, 76_900, 227_100][i % 7],
    paid_minor: i === 1 ? 50_000 : 0,
    credited_minor: i === 2 ? 12_000 : 0,
    outstanding_minor: [214_880, 46_300, 439_620, 128_000, 309_420, 76_900, 227_100][i % 7],
});

const movement = (i: number) => ({
    id: 100 - i,
    occurred_at: new Date(new Date(NOW).getTime() - i * 6 * 86_400_000).toISOString(),
    type: ['payout_reserved', 'applied_to_invoice', 'credit_note', 'reversal', 'credit_note'][i % 5],
    reference: ['—', 'INV-048812', 'CN-000214', null, 'CN-000201'][i % 5],
    amount_minor: [-5_000, -10_000, 12_000, 5_000, 10_450][i % 5],
    balance_after_minor: [12_450, 17_450, 27_450, 15_450, 10_450][i % 5],
});

const members = [
    { id: '01J9USER0001', name: 'Priya Shah', email: 'priya@harbourstreetfoods.co.uk', status: 'active', role: 'owner', order_limit: '', requires_approval: false, is_you: true, is_last_owner: true },
    { id: '01J9USER0002', name: 'Marcus Lee', email: 'marcus.lee@harbourstreetfoods.co.uk', status: 'active', role: 'approver', order_limit: '5000.00', requires_approval: false, is_you: false, is_last_owner: false },
    { id: '01J9USER0003', name: 'Tom Okafor', email: 'tom.okafor@harbourstreetfoods.co.uk', status: 'active', role: 'buyer', order_limit: '1500.00', requires_approval: false, is_you: false, is_last_owner: false },
    { id: '01J9USER0004', name: 'Ella Brennan', email: 'ella.brennan@harbourstreetfoods.co.uk', status: 'active', role: 'buyer', order_limit: '', requires_approval: true, is_you: false, is_last_owner: false },
    { id: '01J9USER0005', name: 'Jonah Whitfield-Abernathy', email: 'jonah.whitfield-abernathy.accounts@harbourstreetfoods.co.uk', status: 'suspended', role: 'viewer', order_limit: '', requires_approval: false, is_you: false, is_last_owner: false },
];

const payOrder = { id: '01J9ORDER000000000000000000', order_number: 'SO-010424', status: 'pending_payment', total_gross_minor: 45_720, pay_by: new Date(new Date(NOW).getTime() + 95 * 60_000).toISOString(), confirmation_url: '/orders/01J9ORDER000000000000000000/confirmation' };

/** Loading and error fixtures: the shell, header and the state each list shows. */
function StateFixture({ title, crumbs, state }: { title: string; crumbs: string; state: 'loading' | 'error' | 'summary-loading' }) {
    return (
        <TradeShell title={title}>
            <PageHeader breadcrumbs={[{ label: crumbs, href: '/order-pad' }, { label: title }]} title={title} />
            {state === 'error' ? (
                <ErrorState message="The server answered 503. Nothing was changed." reference="req_01J9X4N6Q2ZK" onRetry={() => undefined} />
            ) : state === 'summary-loading' ? (
                <div className="flex flex-col gap-8">
                    <SummarySkeleton cards={5} />
                    <TableSkeleton label="Loading invoices" />
                </div>
            ) : (
                <TableSkeleton label={`Loading ${title.toLowerCase()}`} />
            )}
        </TradeShell>
    );
}

export interface Fixture {
    component: string;
    url: string;
    props: Record<string, unknown>;
    render?: ComponentType;
    toasts?: Omit<Toast, 'id'>[];
}

export const FIXTURES: Record<string, Fixture> = {
    'approvals-populated': { component: 'Trade/Approvals/Index', url: '/trade/approvals', props: approvalsProps([0, 1, 2, 3, 4, 5].map((i) => approvalRow(i, i === 3 ? { kind: 'credit_exception', can_decide: false } : {}))) },
    'approvals-decided': {
        component: 'Trade/Approvals/Index',
        url: '/trade/approvals',
        props: approvalsProps(
            [0, 1, 2].map((i) => approvalRow(i, { status: ['approved', 'rejected', 'expired'][i], decided_at: new Date(new Date(NOW).getTime() - i * 86_400_000).toISOString(), decided_by: i === 2 ? null : 'Marcus Lee', can_decide: false })),
            { status: 'decided' },
        ),
    },
    'approvals-empty': { component: 'Trade/Approvals/Index', url: '/trade/approvals', props: approvalsProps([]) },
    'approvals-filtered-empty': { component: 'Trade/Approvals/Index', url: '/trade/approvals', props: approvalsProps([], { q: 'SO-99', buyer: '01J9BUYER2', from: '2026-09-01' }) },
    'approvals-loading': { component: 'Fixture', url: '/trade/approvals', props: {}, render: () => <StateFixture title="Approvals" crumbs="Ordering" state="loading" /> },
    'approvals-error': { component: 'Fixture', url: '/trade/approvals', props: {}, render: () => <StateFixture title="Approvals" crumbs="Ordering" state="error" /> },
    'approval-detail': { component: 'Trade/Approvals/Show', url: '/trade/approvals/01J9APPROVAL00000000000000', props: { approval: detail() } },
    'approval-detail-success': {
        component: 'Trade/Approvals/Show',
        url: '/trade/approvals/01J9APPROVAL00000000000000',
        props: { approval: detail({ status: 'approved', can_decide: false, decided_by: 'Marcus Lee', decided_at: NOW, requests: [{ kind: 'buyer_limit', status: 'approved', decided_by: 'Marcus Lee', decided_at: NOW, reason: null }] }) },
        toasts: [{ tone: 'success', title: 'Order SO-010420 approved', description: 'The order is confirmed and the buyer has been told.' }],
    },
    'approval-detail-unfunded': {
        component: 'Trade/Approvals/Show',
        url: '/trade/approvals/01J9APPROVAL00000000000000',
        props: { approval: detail({ stock_reserved: false, can_decide: false, kind: 'credit_exception', requests: [{ kind: 'buyer_limit', status: 'approved', decided_by: 'Marcus Lee', decided_at: NOW, reason: null }, { kind: 'credit_exception', status: 'pending', decided_by: null, decided_at: null, reason: null }] }) },
    },
    'credit-populated': {
        component: 'Trade/Account/Credit',
        url: '/trade/account/credit',
        props: {
            company,
            summary: creditSummary({
                on_account: { allowed: false, code: 'overdue_debt', message: 'An overdue invoice blocks on-account ordering. Pay it, or pay for this order by card or bank transfer.' },
                overdue: { count: 2, amount_minor: 261_180, oldest_due_at: new Date(new Date(NOW).getTime() - 34 * 86_400_000).toISOString() },
                ageing: [
                    { bucket: 'current', label: 'Not yet due', count: 5, amount_minor: 1_243_040 },
                    { bucket: 'days_1_30', label: '1–30 days overdue', count: 1, amount_minor: 46_300 },
                    { bucket: 'days_31_60', label: '31–60 days overdue', count: 1, amount_minor: 214_880 },
                    { bucket: 'days_61_90', label: '61–90 days overdue', count: 0, amount_minor: 0 },
                    { bucket: 'days_90_plus', label: 'Over 90 days overdue', count: 0, amount_minor: 0 },
                ],
            }),
            rows: [0, 1, 2, 3, 4, 5, 6].map((i) => invoice(i, i === 0 ? 34 : i === 1 ? 12 : 0)),
            next_cursor: 'eyJmaXh0dXJlIjp0cnVlfQ',
        },
    },
    'credit-over-limit': { component: 'Trade/Account/Credit', url: '/trade/account/credit', props: { company, summary: creditSummary({ limit_minor: 1_500_000, available_minor: 0, over_limit_minor: 496_820 }), rows: [invoice(0), invoice(1)], next_cursor: null } },
    'credit-empty': { component: 'Trade/Account/Credit', url: '/trade/account/credit', props: { company, summary: creditSummary({ used_minor: 0, held_minor: 0, available_minor: 2_500_000, ageing: creditSummary().ageing.map((b) => ({ ...b, count: 0, amount_minor: 0 })) }), rows: [], next_cursor: null } },
    'credit-loading': { component: 'Fixture', url: '/trade/account/credit', props: {}, render: () => <StateFixture title="Account credit" crumbs="Account" state="summary-loading" /> },
    'balance-populated': {
        component: 'Trade/Account/Balance',
        url: '/trade/account/balance',
        props: { company, balance_minor: 12_450, payouts: [{ id: '01J9PAYOUT1', method: 'original_card', amount_minor: 5_000, status: 'processing', requested_at: NOW, completed_at: null }], rows: [0, 1, 2, 3, 4].map(movement), next_cursor: null },
    },
    'balance-empty': { component: 'Trade/Account/Balance', url: '/trade/account/balance', props: { company, balance_minor: 0, payouts: [], rows: [], next_cursor: null } },
    'balance-error': { component: 'Fixture', url: '/trade/account/balance', props: {}, render: () => <StateFixture title="Account balance" crumbs="Account" state="error" /> },
    'users-populated': { component: 'Trade/Account/Users', url: '/trade/account/users', props: { company, members } },
    'users-empty': { component: 'Trade/Account/Users', url: '/trade/account/users', props: { company, members: [] } },
    'users-success': {
        component: 'Trade/Account/Users',
        url: '/trade/account/users',
        props: { company, members },
        toasts: [{ tone: 'success', title: 'Tom Okafor updated', description: 'Order limit: £1500.00 → £2500.00' }],
    },
    'pay-card': { component: 'Trade/Orders/Pay', url: '/trade/orders/01J9ORDER000000000000000000/pay', props: { order: payOrder, refusal: null, can_pay_in_advance: false, stripe_key: 'pk_test_fixture' } },
    'pay-in-advance': { component: 'Trade/Orders/Pay', url: '/trade/orders/01J9ORDER000000000000000000/pay', props: { order: { ...payOrder, status: 'awaiting_approval', pay_by: null }, refusal: null, can_pay_in_advance: true, stripe_key: 'pk_test_fixture' } },
    'pay-closed': { component: 'Trade/Orders/Pay', url: '/trade/orders/01J9ORDER000000000000000000/pay', props: { order: { ...payOrder, status: 'cancelled', pay_by: null }, refusal: { code: 'order_not_payable', message: 'This order is not waiting for payment.' }, can_pay_in_advance: false, stripe_key: 'pk_test_fixture' } },
};
