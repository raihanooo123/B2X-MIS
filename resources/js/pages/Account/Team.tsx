/**
 * An owner's Team page (05.13 §9, 05.2 §10): who can order for the
 * company, with what role and order limit, and the invitations sent.
 * Every change goes through the server, which enforces the rules and
 * audits it; this page only presents them.
 */
import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Mail, Pencil, RotateCw, Trash2, UserPlus, XCircle } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import { Field, FormError } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

import {
    Avatar,
    Dialog,
    MembershipFields,
    Pill,
    pounds,
    RolePill,
    RowMenu,
    StatePill,
    type Invitation,
    type Member,
    type TeamManagement,
} from './components/team';

interface TeamProps {
    company: { name: string; account_code: string };
    management: TeamManagement;
    status: string | null;
}

export default function Team({ company, management, status }: TeamProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [inviting, setInviting] = useState(false);
    const [editing, setEditing] = useState<Member | null>(null);
    const [removing, setRemoving] = useState<Member | null>(null);
    const [revoking, setRevoking] = useState<Invitation | null>(null);

    const pending = management.invitations.filter((i) => i.manageable);
    const history = management.invitations.filter((i) => !i.manageable);
    const waiting = pending.filter((i) => i.state === 'Invited').length;
    const pageError = errors.role ?? errors.member ?? null;

    return (
        <AccountLayout
            title="Team"
            eyebrow={`${company.name} · ${company.account_code}`}
            description="Everyone who can order for this account, their role and their order limit."
            status={status}
            actions={
                <Button className="h-11 gap-2 md:h-10" onClick={() => setInviting(true)}>
                    <UserPlus className="size-4" aria-hidden /> Invite member
                </Button>
            }
        >
            {pageError && !editing && (
                <div className="mb-5">
                    <FormError message={pageError} />
                </div>
            )}

            <div className="space-y-6">
                <AccountCard title="Members" description={`${management.members.length} ${management.members.length === 1 ? 'person' : 'people'}`}>
                    <table className="w-full text-sm">
                        <thead className="hidden border-b text-left text-xs text-muted-foreground md:table-header-group">
                            <tr>
                                <th className="px-5 py-2.5 font-medium">Name</th>
                                <th className="px-3 py-2.5 font-medium">Role</th>
                                <th className="px-3 py-2.5 text-right font-medium">Order limit</th>
                                <th className="px-3 py-2.5 font-medium">Approval</th>
                                <th className="px-3 py-2.5 font-medium">Status</th>
                                <th className="w-14 px-3 py-2.5">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {management.members.map((member) => (
                                <tr key={member.id} className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1.5 px-5 py-3.5 hover:bg-muted/30 md:table-row md:p-0">
                                    <td className="md:px-5 md:py-3.5">
                                        <span className="flex items-center gap-3">
                                            <Avatar name={member.name} />
                                            <span className="min-w-0">
                                                <span className="flex items-center gap-1.5 font-medium">
                                                    <span className="truncate">{member.name}</span>
                                                    {member.is_you && <span className="rounded bg-muted px-1.5 py-px text-[11px] font-medium text-muted-foreground">You</span>}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">{member.email}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td className="col-start-1 md:px-3 md:py-3.5">
                                        <span className="flex flex-wrap items-center gap-1.5">
                                            <RolePill role={member.role} />
                                            <span className="md:hidden">
                                                <StatePill state={member.status} />
                                            </span>
                                        </span>
                                    </td>
                                    <td className="col-start-1 text-muted-foreground tabular-nums md:px-3 md:py-3.5 md:text-right md:text-foreground">
                                        <span className="md:hidden">Limit </span>
                                        {pounds(member.order_limit)}
                                        {member.requires_approval && <span className="md:hidden"> · needs approval</span>}
                                    </td>
                                    <td className="hidden md:table-cell md:px-3 md:py-3.5">
                                        {member.requires_approval ? <Pill tone="bg-amber-50 text-amber-800 ring-amber-200">Required</Pill> : <span className="text-muted-foreground">—</span>}
                                    </td>
                                    <td className="hidden md:table-cell md:px-3 md:py-3.5">
                                        <StatePill state={member.status} />
                                    </td>
                                    <td className="col-start-2 row-span-3 row-start-1 self-start text-right md:px-3 md:py-3.5">
                                        <RowMenu
                                            label={`Actions for ${member.name}`}
                                            actions={[
                                                { label: 'Edit role and limit', icon: <Pencil className="size-4" aria-hidden />, onSelect: () => setEditing(member) },
                                                {
                                                    label: member.is_you ? 'Leave this company' : 'Remove from company',
                                                    icon: <Trash2 className="size-4" aria-hidden />,
                                                    danger: true,
                                                    disabled: member.is_last_owner,
                                                    hint: 'The only active owner can’t be removed.',
                                                    onSelect: () => setRemoving(member),
                                                },
                                            ]}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </AccountCard>

                <AccountCard title="Invitations" description={waiting > 0 ? `${waiting} waiting to be accepted` : 'No invitations waiting'}>
                    {pending.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-5 py-8 text-center text-sm text-muted-foreground">
                            <Mail className="size-6" aria-hidden />
                            <p>Invite colleagues so each person has their own login, role and order limit.</p>
                            <Button variant="outline" className="mt-1 h-11 gap-2 md:h-9" onClick={() => setInviting(true)}>
                                <UserPlus className="size-4" aria-hidden /> Invite member
                            </Button>
                        </div>
                    ) : (
                        <ul className="divide-y text-sm">
                            {pending.map((invitation) => (
                                <li key={invitation.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                                    <span className="inline-flex size-9 items-center justify-center rounded-full bg-muted">
                                        <Mail className="size-4 text-muted-foreground" aria-hidden />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">{invitation.email}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {invitation.state === 'Expired' ? `Expired ${invitation.expires_at}` : `Sent ${invitation.sent_at} · expires ${invitation.expires_at}`}
                                        </span>
                                    </span>
                                    <RolePill role={invitation.role} />
                                    <span className="w-28 text-right tabular-nums">{pounds(invitation.order_limit)}</span>
                                    <StatePill state={invitation.state} />
                                    <RowMenu
                                        label={`Actions for the invitation to ${invitation.email}`}
                                        actions={[
                                            {
                                                label: invitation.state === 'Expired' ? 'Send a new invitation' : 'Resend invitation',
                                                icon: <RotateCw className="size-4" aria-hidden />,
                                                onSelect: () => router.post(`/account/invitations/${invitation.id}/resend`, {}, { preserveScroll: true }),
                                            },
                                            { label: 'Revoke invitation', icon: <XCircle className="size-4" aria-hidden />, danger: true, onSelect: () => setRevoking(invitation) },
                                        ]}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    {history.length > 0 && (
                        <details className="group border-t">
                            <summary className="flex min-h-11 cursor-pointer list-none items-center gap-1.5 px-5 text-sm text-muted-foreground hover:text-foreground">
                                <ChevronRight className="size-4 transition-transform group-open:rotate-90" aria-hidden />
                                Past invitations ({history.length})
                            </summary>
                            <ul className="divide-y border-t bg-muted/20 text-sm">
                                {history.map((invitation) => (
                                    <li key={invitation.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-2.5 text-muted-foreground">
                                        <span className="min-w-0 flex-1 truncate">{invitation.email}</span>
                                        <span>{invitation.sent_at}</span>
                                        <StatePill state={invitation.state} />
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </AccountCard>
            </div>

            <InviteDialog open={inviting} companyId={management.company_id} onClose={() => setInviting(false)} />
            {editing && <EditDialog member={editing} companyId={management.company_id} onClose={() => setEditing(null)} />}
            <ConfirmDialog
                open={removing !== null}
                title={removing?.is_you ? 'Leave this company?' : `Remove ${removing?.name}?`}
                description={
                    removing?.is_you
                        ? 'You will no longer be able to order for this company.'
                        : 'They will no longer be able to order for this company. Their login stays, and you can invite them again later.'
                }
                confirmLabel={removing?.is_you ? 'Leave' : 'Remove'}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) {
                        router.delete(`/account/companies/${management.company_id}/members/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) });
                    }
                }}
            />
            <ConfirmDialog
                open={revoking !== null}
                title="Revoke invitation?"
                description={`The link sent to ${revoking?.email ?? ''} will stop working.`}
                confirmLabel="Revoke"
                onClose={() => setRevoking(null)}
                onConfirm={() => {
                    if (revoking) {
                        router.delete(`/account/invitations/${revoking.id}`, { preserveScroll: true, onFinish: () => setRevoking(null) });
                    }
                }}
            />
        </AccountLayout>
    );
}

function InviteDialog({ open, companyId, onClose }: { open: boolean; companyId: string; onClose: () => void }) {
    const form = useForm({ first_name: '', last_name: '', email: '', role: 'buyer', order_limit: '', requires_approval: false });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/account/companies/${companyId}/invitations`, { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} title="Invite a team member" description="They get an email with a link to join. It works for 7 days." onClose={close}>
            <form onSubmit={submit} className="space-y-4" noValidate>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="First name" required autoComplete="off" value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} error={form.errors.first_name} />
                    <Field label="Last name" required autoComplete="off" value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} error={form.errors.last_name} />
                </div>
                <Field label="Work email" type="email" required autoComplete="off" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
                <MembershipFields
                    role={form.data.role}
                    orderLimit={form.data.order_limit}
                    requiresApproval={form.data.requires_approval}
                    errors={form.errors}
                    onChange={(key, value) => form.setData(key, value as never)}
                />
                <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" className="h-11 md:h-10" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" className="h-11 md:h-10" disabled={form.processing}>
                        Send invitation
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

function EditDialog({ member, companyId, onClose }: { member: Member; companyId: string; onClose: () => void }) {
    const form = useForm({ role: member.role, order_limit: member.order_limit, requires_approval: member.requires_approval });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(`/account/companies/${companyId}/members/${member.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open title={`Edit ${member.is_you ? 'your access' : member.name}`} description={member.email} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4" noValidate>
                <MembershipFields
                    role={form.data.role}
                    orderLimit={form.data.order_limit}
                    requiresApproval={form.data.requires_approval}
                    errors={form.errors}
                    roleLocked={member.is_last_owner}
                    onChange={(key, value) => form.setData(key, value as never)}
                />
                <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" className="h-11 md:h-10" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" className="h-11 md:h-10" disabled={form.processing}>
                        Save changes
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel,
    onClose,
    onConfirm,
}: {
    open: boolean;
    title: string;
    description: string;
    confirmLabel: string;
    onClose: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} title={title} description={description} onClose={onClose}>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="outline" className="h-11 md:h-10" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="button" variant="destructive" className="h-11 md:h-10" onClick={onConfirm}>
                    {confirmLabel}
                </Button>
            </div>
        </Dialog>
    );
}
