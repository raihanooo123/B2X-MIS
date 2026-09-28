/**
 * An owner's Team page (05.13 §9, 05.2 §10): who can order for the
 * company, with what role and order limit, and the invitations sent.
 * Every change goes through the server, which enforces the rules and
 * audits it; this page only presents them.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Mail, Pencil, RotateCw, Trash2, UserPlus, XCircle } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Field, FormError } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

import {
    Avatar,
    Dialog,
    MembershipFields,
    pounds,
    RolePill,
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
    const openInvitations = management.invitations.filter((i) => i.state === 'Invited').length;
    const pageError = errors.role ?? errors.member ?? null;

    return (
        <>
            <Head title="Team" />
            <div className="mx-auto max-w-5xl px-4 py-4">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="flex items-center gap-3">
                        <Link href="/account" className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
                            <ArrowLeft className="size-4" aria-hidden /> Your account
                        </Link>
                        <h1 className="text-lg font-semibold tracking-tight">Team</h1>
                    </div>
                    <AccountMenu />
                </header>

                {status && (
                    <p role="status" className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                        {status}
                    </p>
                )}
                {pageError && !editing && (
                    <div className="mb-4">
                        <FormError message={pageError} />
                    </div>
                )}

                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {company.name} · {company.account_code}
                        </p>
                        <h2 className="text-2xl font-semibold tracking-tight">People who can order</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {management.members.length} {management.members.length === 1 ? 'person' : 'people'}
                            {openInvitations > 0 && ` · ${openInvitations} invitation${openInvitations === 1 ? '' : 's'} waiting`}
                        </p>
                    </div>
                    <Button className="h-11 gap-2 md:h-10" onClick={() => setInviting(true)}>
                        <UserPlus className="size-4" aria-hidden /> Invite member
                    </Button>
                </div>

                <section aria-label="Members" className="overflow-hidden rounded-xl border bg-background">
                    <table className="w-full text-sm">
                        <thead className="hidden bg-muted/40 text-left text-xs font-medium uppercase tracking-wide text-muted-foreground md:table-header-group">
                            <tr>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Role</th>
                                <th className="px-4 py-3 font-medium">Order limit</th>
                                <th className="px-4 py-3 font-medium">Approval</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {management.members.map((member) => (
                                <tr key={member.id} className="flex flex-col gap-2 px-4 py-3 md:table-row md:p-0">
                                    <td className="md:px-4 md:py-3">
                                        <span className="flex items-center gap-3">
                                            <Avatar name={member.name} />
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">
                                                    {member.name}
                                                    {member.is_you && <span className="ml-1.5 text-xs font-normal text-muted-foreground">(you)</span>}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">{member.email}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td className="md:px-4 md:py-3">
                                        <RolePill role={member.role} />
                                    </td>
                                    <td className="tabular-nums md:px-4 md:py-3">
                                        <span className="text-xs text-muted-foreground md:hidden">Order limit: </span>
                                        {pounds(member.order_limit)}
                                    </td>
                                    <td className="md:px-4 md:py-3">
                                        {member.requires_approval ? (
                                            <span className="text-amber-800">Needs approval</span>
                                        ) : (
                                            <span className="text-muted-foreground">Not required</span>
                                        )}
                                    </td>
                                    <td className="md:px-4 md:py-3">
                                        <StatePill state={member.status} />
                                    </td>
                                    <td className="md:px-4 md:py-3 md:text-right">
                                        <span className="inline-flex gap-1">
                                            <Button variant="ghost" className="h-11 gap-1.5 md:h-8" onClick={() => setEditing(member)}>
                                                <Pencil className="size-3.5" aria-hidden /> Edit
                                                <span className="sr-only"> {member.name}</span>
                                            </Button>
                                            <Button variant="ghost" className="h-11 gap-1.5 text-red-700 hover:bg-red-50 hover:text-red-800 md:h-8" onClick={() => setRemoving(member)}>
                                                <Trash2 className="size-3.5" aria-hidden /> Remove
                                                <span className="sr-only"> {member.name}</span>
                                            </Button>
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section aria-labelledby="invitations-heading" className="mt-8">
                    <h2 id="invitations-heading" className="mb-3 text-base font-semibold">
                        Invitations
                    </h2>
                    {management.invitations.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                            <Mail className="size-6" aria-hidden />
                            No invitations yet. Invite colleagues so each person has their own login and limit.
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-xl border bg-background">
                            <table className="w-full text-sm">
                                <thead className="hidden bg-muted/40 text-left text-xs font-medium uppercase tracking-wide text-muted-foreground md:table-header-group">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Email</th>
                                        <th className="px-4 py-3 font-medium">Role</th>
                                        <th className="px-4 py-3 font-medium">Order limit</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        <th className="px-4 py-3 font-medium">Sent</th>
                                        <th className="px-4 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {management.invitations.map((invitation) => (
                                        <tr key={invitation.id} className="flex flex-col gap-2 px-4 py-3 md:table-row md:p-0">
                                            <td className="truncate font-medium md:px-4 md:py-3">{invitation.email}</td>
                                            <td className="md:px-4 md:py-3">
                                                <RolePill role={invitation.role} />
                                            </td>
                                            <td className="tabular-nums md:px-4 md:py-3">{pounds(invitation.order_limit)}</td>
                                            <td className="md:px-4 md:py-3">
                                                <StatePill state={invitation.state} />
                                            </td>
                                            <td className="text-muted-foreground md:px-4 md:py-3">
                                                {invitation.sent_at}
                                                {invitation.state === 'Invited' && <span className="block text-xs">Expires {invitation.expires_at}</span>}
                                            </td>
                                            <td className="md:px-4 md:py-3 md:text-right">
                                                {invitation.manageable && (
                                                    <span className="inline-flex gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            className="h-11 gap-1.5 md:h-8"
                                                            onClick={() => router.post(`/account/invitations/${invitation.id}/resend`, {}, { preserveScroll: true })}
                                                        >
                                                            <RotateCw className="size-3.5" aria-hidden /> Resend
                                                        </Button>
                                                        <Button variant="ghost" className="h-11 gap-1.5 text-red-700 hover:bg-red-50 hover:text-red-800 md:h-8" onClick={() => setRevoking(invitation)}>
                                                            <XCircle className="size-3.5" aria-hidden /> Revoke
                                                        </Button>
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <InviteDialog open={inviting} companyId={management.company_id} onClose={() => setInviting(false)} />
            {editing && <EditDialog member={editing} companyId={management.company_id} onClose={() => setEditing(null)} />}
            <ConfirmDialog
                open={removing !== null}
                title={removing?.is_you ? 'Remove yourself?' : `Remove ${removing?.name}?`}
                description={
                    removing?.is_you
                        ? 'You will no longer be able to order for this company.'
                        : 'They will no longer be able to order for this company. Their login stays, and you can invite them again later.'
                }
                confirmLabel="Remove"
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
        </>
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
