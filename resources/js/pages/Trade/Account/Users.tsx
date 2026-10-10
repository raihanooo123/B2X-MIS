/**
 * 05.2 §18.3 — company users, for owners: each member's role, per-order
 * limit and whether every order needs approval. Edit opens a form; a
 * permission change is confirmed, showing before and after, then saved
 * through PATCH /api/v1/company-users/{user_id}. Validation errors stay
 * beside their fields with a linked summary; the server keeps the last
 * active owner and audits every change.
 */
import { router } from '@inertiajs/react';
import { Users as UsersIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { ConfirmDialog } from '@/components/trade/ConfirmDialog';
import { DataTable, type Column } from '@/components/trade/DataTable';
import { ErrorSummary } from '@/components/trade/ErrorSummary';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ApiError } from '@/lib/api/client';
import { updateCompanyUser, type MemberSettings } from '@/lib/api/credit';
import { toast } from '@/stores/toastStore';

interface Member {
    id: string;
    name: string;
    email: string;
    status: string;
    role: MemberSettings['role'];
    /** Pounds, as the form takes it; '' for no limit. */
    order_limit: string;
    requires_approval: boolean;
    is_you: boolean;
    is_last_owner: boolean;
}

const ROLES: { value: MemberSettings['role']; label: string; help: string }[] = [
    { value: 'owner', label: 'Owner', help: 'Orders, manages users and limits, sees all orders and invoices.' },
    { value: 'approver', label: 'Approver', help: 'Orders, and approves other buyers’ orders above their limits.' },
    { value: 'buyer', label: 'Buyer', help: 'Orders within their limit; sees their own orders.' },
    { value: 'viewer', label: 'Viewer', help: 'Sees prices and order history; cannot order.' },
];

const roleLabel = (role: string) => ROLES.find((r) => r.value === role)?.label ?? role;
const limitLabel = (limit: string) => (limit === '' ? 'No limit' : `£${limit}`);

const FIELD_IDS = { role: 'member-role', order_limit: 'member-limit', requires_approval: 'member-approval' };

export default function Users({ company, members }: { company: { name: string; account_code: string }; members: Member[] }) {
    const [editing, setEditing] = useState<Member | null>(null);
    const [form, setForm] = useState<MemberSettings>({ role: 'buyer', order_limit: '', requires_approval: false });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirming, setConfirming] = useState(false);
    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    const open = (member: Member) => {
        setEditing(member);
        setForm({ role: member.role, order_limit: member.order_limit, requires_approval: member.requires_approval });
        setErrors({});
        setFailure(null);
    };

    const changes = editing === null ? [] : [
        ...(form.role !== editing.role ? [{ label: 'Role', value: `${roleLabel(editing.role)} → ${roleLabel(form.role)}` }] : []),
        ...(form.order_limit.trim() !== editing.order_limit ? [{ label: 'Order limit', value: `${limitLabel(editing.order_limit)} → ${limitLabel(form.order_limit.trim())}` }] : []),
        ...(form.requires_approval !== editing.requires_approval ? [{ label: 'Every order needs approval', value: `${editing.requires_approval ? 'Yes' : 'No'} → ${form.requires_approval ? 'Yes' : 'No'}` }] : []),
    ];

    const review = (e: FormEvent) => {
        e.preventDefault();
        const local: Record<string, string> = {};
        if (form.order_limit.trim() !== '' && !/^\d{1,9}(\.\d{1,2})?$/.test(form.order_limit.trim())) {
            local.order_limit = 'Enter the order limit in pounds, e.g. 2500 or 2500.50.';
        }
        setErrors(local);
        if (Object.keys(local).length > 0) {
            return;
        }
        if (changes.length === 0) {
            setEditing(null);

            return;
        }
        setConfirming(true);
    };

    const save = async () => {
        if (editing === null) {
            return;
        }
        setPending(true);
        setFailure(null);
        try {
            await updateCompanyUser(editing.id, form);
            toast.success(`${editing.name} updated`, changes.map((c) => c.value).join(' · '));
            setConfirming(false);
            setEditing(null);
            router.reload({ only: ['members'] });
        } catch (error) {
            const apiError = error as ApiError;
            if (apiError.code === 'validation_failed') {
                setErrors(Object.fromEntries(apiError.details.filter((d) => d.field).map((d) => [String(d.field), d.message])));
                setConfirming(false);
            } else {
                setFailure(apiError.message);
            }
        } finally {
            setPending(false);
        }
    };

    const columns: Column<Member>[] = [
        {
            id: 'name',
            header: 'Name',
            inCardTitle: true,
            cell: (m) => (
                <span>
                    <span className="font-medium">{m.name}{m.is_you && <span className="text-muted-foreground"> (you)</span>}</span>
                    <span className="block break-all text-xs text-muted-foreground">{m.email}</span>
                </span>
            ),
        },
        { id: 'role', header: 'Role', cell: (m) => roleLabel(m.role) },
        { id: 'limit', header: 'Per-order limit', align: 'end', cell: (m) => <span className="tabular-nums">{m.role === 'viewer' ? '—' : limitLabel(m.order_limit)}</span> },
        { id: 'approval', header: 'Needs approval', cell: (m) => (m.requires_approval ? 'Every order' : m.order_limit === '' ? 'Never' : 'Above limit') },
        { id: 'status', header: 'Status', priority: 'secondary', cell: (m) => <StatusBadge tone={m.status === 'active' ? 'success' : 'neutral'}>{m.status === 'active' ? 'Active' : m.status === 'suspended' ? 'Suspended' : m.status}</StatusBadge> },
    ];

    return (
        <TradeShell title="Users and limits">
            <PageHeader
                breadcrumbs={[{ label: 'Account', href: '/account' }, { label: 'Users and limits' }]}
                title="Users and limits"
                description={<p>Who can order for {company.name}, up to how much per order, and whose orders need approval. Orders above a buyer&apos;s limit go to your owners and approvers for 48 hours.</p>}
                primaryAction={<Button asChild variant="outline" className="h-11"><a href="/account/team">Invite someone</a></Button>}
            />

            {members.length === 0 ? (
                <EmptyState icon={UsersIcon} title="No members yet" action={<Button asChild className="h-11"><a href="/account/team">Invite someone</a></Button>}>
                    Invite your colleagues and choose what each of them may order.
                </EmptyState>
            ) : (
                <DataTable
                    caption={`Members of ${company.name}`}
                    columns={columns}
                    rows={members}
                    rowKey={(m) => m.id}
                    rowLabel={(m) => m.name}
                    cardTitle={(m) => (
                        <span>
                            {m.name}{m.is_you && <span className="font-normal text-muted-foreground"> (you)</span>}
                            <span className="block break-all text-xs font-normal text-muted-foreground">{m.email}</span>
                        </span>
                    )}
                    rowAction={(m) => (
                        <Button variant="outline" className="h-11" onClick={() => open(m)}>
                            Edit<span className="sr-only"> {m.name}</span>
                        </Button>
                    )}
                />
            )}

            <Dialog open={editing !== null && !confirming} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit {editing?.name}</DialogTitle>
                        <DialogDescription>Changes take effect on their next order. Orders already waiting keep their 48-hour window.</DialogDescription>
                    </DialogHeader>
                    <form id="member-form" onSubmit={review} className="flex flex-col gap-5" noValidate>
                        <ErrorSummary errors={errors} fieldIds={FIELD_IDS} />
                        <fieldset className="flex flex-col gap-2">
                            <legend className="mb-1 text-sm font-medium" id={FIELD_IDS.role}>Role</legend>
                            {ROLES.map((role) => {
                                const locked = editing?.is_last_owner === true && role.value !== 'owner';

                                return (
                                    <label key={role.value} className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border p-3 has-[:checked]:border-primary has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">
                                        <input type="radio" name="role" value={role.value} checked={form.role === role.value} disabled={locked} onChange={() => setForm({ ...form, role: role.value })} className="mt-0.5 size-5 accent-primary" />
                                        <span className="text-sm">
                                            <span className="font-medium">{role.label}</span>
                                            <span className="block text-muted-foreground">{role.help}</span>
                                        </span>
                                    </label>
                                );
                            })}
                            {editing?.is_last_owner && <p className="text-sm text-muted-foreground">The only active owner stays an owner. Make someone else an owner first.</p>}
                            {errors.role && <p className="text-sm text-red-800">{errors.role}</p>}
                        </fieldset>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={FIELD_IDS.order_limit}>Per-order limit (£, including VAT)</Label>
                            <Input id={FIELD_IDS.order_limit} inputMode="decimal" value={form.order_limit} onChange={(e) => setForm({ ...form, order_limit: e.target.value })} aria-invalid={errors.order_limit !== undefined} aria-describedby="limit-help" className="h-11 max-w-48" disabled={form.role === 'viewer'} />
                            <p id="limit-help" className="text-sm text-muted-foreground">Leave empty for no limit. Orders above it need approval.</p>
                            {errors.order_limit && <p className="text-sm text-red-800">{errors.order_limit}</p>}
                        </div>
                        <label className="flex min-h-11 items-center gap-3 text-sm" htmlFor={FIELD_IDS.requires_approval}>
                            <input id={FIELD_IDS.requires_approval} type="checkbox" checked={form.requires_approval} onChange={(e) => setForm({ ...form, requires_approval: e.target.checked })} className="size-5 accent-primary" disabled={form.role === 'viewer'} />
                            Every order this person places needs approval
                        </label>
                    </form>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" className="h-11" onClick={() => setEditing(null)}>Cancel</Button>
                        <Button type="submit" form="member-form" className="h-11">Review changes</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirming}
                onOpenChange={(o) => { setConfirming(o); setFailure(null); }}
                title={`Change ${editing?.name ?? 'this member'}'s permissions?`}
                facts={[{ label: 'Company', value: company.name }, { label: 'Member', value: editing?.email ?? '' }, ...changes]}
                consequences="The change is recorded with your name. It applies from their next order."
                confirmLabel="Save changes"
                pending={pending}
                error={failure}
                onConfirm={save}
            />
        </TradeShell>
    );
}
