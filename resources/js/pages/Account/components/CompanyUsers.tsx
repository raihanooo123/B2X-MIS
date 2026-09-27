/**
 * The account page's company sections (05.13 §9, 05.2 §10):
 *  - invitations waiting for the signed-in person, which they can accept;
 *  - for a company owner, the company's members (role, order limit,
 *    approval, remove) and its invitations (invite, resend, revoke).
 *
 * Order limits are typed and shown in pounds; the server stores whole
 * pence. The server enforces every rule — including that an approved or
 * suspended company keeps one active owner — and its message is shown.
 */
import { router, useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';

import { Checkbox, Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

export interface PendingInvitation {
    id: string;
    company: string;
    role: string;
}

export interface CompanyManagement {
    company_id: string;
    members: {
        id: string;
        name: string;
        email: string;
        status: string;
        role: string;
        order_limit: string;
        requires_approval: boolean;
        is_you: boolean;
    }[];
    invitations: {
        id: string;
        email: string;
        role: string;
        order_limit: string | null;
        state: 'Invited' | 'Accepted' | 'Revoked' | 'Expired';
        manageable: boolean;
    }[];
}

const ROLES = [
    { value: 'owner', label: 'Owner', help: 'Manages users and orders' },
    { value: 'buyer', label: 'Buyer', help: 'Places orders' },
    { value: 'approver', label: 'Approver', help: 'Approves orders over a limit' },
    { value: 'viewer', label: 'Viewer', help: 'Sees orders and invoices only' },
];

function roleLabel(role: string): string {
    return ROLES.find((r) => r.value === role)?.label ?? role;
}

function pounds(limit: string | null): string {
    if (!limit) {
        return 'No limit';
    }
    const [whole, pence = '00'] = limit.split('.');
    return `£${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${pence.padEnd(2, '0')}`;
}

export function PendingInvitations({ invitations }: { invitations: PendingInvitation[] }) {
    return (
        <ul className="divide-y text-sm">
            {invitations.map((invitation) => (
                <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <span>
                        <strong>{invitation.company}</strong> invited you as {roleLabel(invitation.role).toLowerCase()}.
                    </span>
                    <Button
                        className="h-11 md:h-9"
                        onClick={() => router.post(`/account/invitations/${invitation.id}/accept`, {}, { preserveScroll: true })}
                    >
                        Accept
                    </Button>
                </li>
            ))}
        </ul>
    );
}

export function CompanyUsers({ management }: { management: CompanyManagement }) {
    return (
        <div className="space-y-6 text-sm">
            <section className="space-y-3">
                <h3 className="font-semibold">People</h3>
                <ul className="divide-y rounded-md border">
                    {management.members.map((member) => (
                        <MemberRow key={member.id} companyId={management.company_id} member={member} />
                    ))}
                </ul>
            </section>

            <section className="space-y-3">
                <h3 className="font-semibold">Invite someone</h3>
                <InviteForm companyId={management.company_id} />
            </section>

            {management.invitations.length > 0 && (
                <section className="space-y-3">
                    <h3 className="font-semibold">Invitations</h3>
                    <ul className="divide-y rounded-md border">
                        {management.invitations.map((invitation) => (
                            <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <span className="min-w-0">
                                    <span className="block truncate">{invitation.email}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {roleLabel(invitation.role)} · {pounds(invitation.order_limit)} · {invitation.state}
                                    </span>
                                </span>
                                {invitation.manageable && (
                                    <span className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            className="h-11 md:h-8"
                                            onClick={() => router.post(`/account/invitations/${invitation.id}/resend`, {}, { preserveScroll: true })}
                                        >
                                            Resend
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="h-11 md:h-8"
                                            onClick={() => {
                                                if (window.confirm(`Revoke the invitation to ${invitation.email}? The link will stop working.`)) {
                                                    router.delete(`/account/invitations/${invitation.id}`, { preserveScroll: true });
                                                }
                                            }}
                                        >
                                            Revoke
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

function MemberRow({ companyId, member }: { companyId: string; member: CompanyManagement['members'][number] }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ role: member.role, order_limit: member.order_limit, requires_approval: member.requires_approval });
    const url = `/account/companies/${companyId}/members/${member.id}`;

    const save = (e: FormEvent) => {
        e.preventDefault();
        form.patch(url, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const remove = () => {
        const who = member.is_you ? 'yourself' : member.name;
        if (window.confirm(`Remove ${who} from this company?`)) {
            router.delete(url, { preserveScroll: true });
        }
    };

    return (
        <li className="space-y-3 px-3 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0">
                    <span className="block truncate font-medium">
                        {member.name}
                        {member.is_you && <span className="ml-1 font-normal text-muted-foreground">(you)</span>}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {member.email} · {roleLabel(member.role)} · {pounds(member.order_limit)}
                        {member.requires_approval && ' · needs approval'}
                        {member.status !== 'active' && ` · ${member.status}`}
                    </span>
                </span>
                {!editing && (
                    <span className="flex gap-2">
                        <Button variant="outline" className="h-11 md:h-8" onClick={() => setEditing(true)}>
                            Change
                        </Button>
                        <Button variant="outline" className="h-11 md:h-8" onClick={remove}>
                            Remove
                        </Button>
                    </span>
                )}
            </div>
            {(form.errors.role || form.errors.order_limit) && !editing && <p className="text-xs text-red-700">{form.errors.role ?? form.errors.order_limit}</p>}
            {editing && (
                <form onSubmit={save} className="space-y-3" noValidate>
                    <MembershipFields
                        role={form.data.role}
                        orderLimit={form.data.order_limit}
                        requiresApproval={form.data.requires_approval}
                        errors={form.errors}
                        onChange={(key, value) => form.setData(key, value as never)}
                    />
                    <span className="flex gap-2">
                        <Button type="submit" className="h-11 md:h-9" disabled={form.processing}>
                            Save
                        </Button>
                        <Button type="button" variant="outline" className="h-11 md:h-9" onClick={() => { form.reset(); form.clearErrors(); setEditing(false); }}>
                            Cancel
                        </Button>
                    </span>
                </form>
            )}
        </li>
    );
}

function InviteForm({ companyId }: { companyId: string }) {
    const form = useForm({ email: '', first_name: '', last_name: '', role: 'buyer', order_limit: '', requires_approval: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/account/companies/${companyId}/invitations`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border p-3" noValidate>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="First name" required value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} error={form.errors.first_name} />
                <Field label="Last name" required value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} error={form.errors.last_name} />
            </div>
            <Field label="Email" type="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
            <MembershipFields
                role={form.data.role}
                orderLimit={form.data.order_limit}
                requiresApproval={form.data.requires_approval}
                errors={form.errors}
                onChange={(key, value) => form.setData(key, value as never)}
            />
            <Button type="submit" className="h-11 md:h-9" disabled={form.processing}>
                Send invitation
            </Button>
            <p className="text-xs text-muted-foreground">The link works for 7 days. Resending replaces the old link.</p>
        </form>
    );
}

function MembershipFields({
    role,
    orderLimit,
    requiresApproval,
    errors,
    onChange,
}: {
    role: string;
    orderLimit: string;
    requiresApproval: boolean;
    errors: Partial<Record<string, string>>;
    onChange: (key: 'role' | 'order_limit' | 'requires_approval', value: string | boolean) => void;
}) {
    const roleId = useId();

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
                <label className="text-sm font-medium" htmlFor={roleId}>
                    Role
                </label>
                <select
                    id={roleId}
                    value={role}
                    onChange={(e) => onChange('role', e.target.value)}
                    className="h-11 w-full rounded-md border border-input bg-background px-3 text-sm md:h-10"
                >
                    {ROLES.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label} — {r.help}
                        </option>
                    ))}
                </select>
                {errors.role && <p className="text-xs text-red-700">{errors.role}</p>}
            </div>
            <Field
                label="Order limit (£)"
                inputMode="decimal"
                placeholder="No limit"
                value={orderLimit}
                onChange={(e) => onChange('order_limit', e.target.value)}
                error={errors.order_limit}
                hint="The most one order may total. Leave empty for no limit."
            />
            <div className="sm:col-span-2">
                <Checkbox label="Orders need approval before they are placed" checked={requiresApproval} onChange={(checked) => onChange('requires_approval', checked)} />
            </div>
        </div>
    );
}
