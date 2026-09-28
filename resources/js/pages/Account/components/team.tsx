/**
 * Building blocks for the owner's Team page and the account page's team
 * card (05.13 §9, 05.2 §10). Order limits are typed and shown in pounds;
 * the server stores whole pence and enforces every rule, including that an
 * approved or suspended company keeps one active owner.
 */
import { router } from '@inertiajs/react';
import { MoreHorizontal, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

import { Checkbox, Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface PendingInvitation {
    id: string;
    company: string;
    role: string;
}

export interface Member {
    id: string;
    name: string;
    email: string;
    status: string;
    role: string;
    order_limit: string;
    requires_approval: boolean;
    is_you: boolean;
    /** The only active owner of a trading company: can't be removed or demoted. */
    is_last_owner: boolean;
}

export interface Invitation {
    id: string;
    email: string;
    role: string;
    order_limit: string | null;
    state: 'Invited' | 'Accepted' | 'Revoked' | 'Expired';
    manageable: boolean;
    sent_at: string;
    expires_at: string;
}

export interface TeamManagement {
    company_id: string;
    members: Member[];
    invitations: Invitation[];
}

export const ROLES = [
    { value: 'owner', label: 'Owner', help: 'Manages the team and orders' },
    { value: 'buyer', label: 'Buyer', help: 'Places orders' },
    { value: 'approver', label: 'Approver', help: 'Approves orders over a limit' },
    { value: 'viewer', label: 'Viewer', help: 'Sees orders and invoices only' },
] as const;

export function roleLabel(role: string): string {
    return ROLES.find((r) => r.value === role)?.label ?? role;
}

/** "2500.5" → "£2,500.50"; empty → "No limit". String maths only — no floats. */
export function pounds(limit: string | null): string {
    if (!limit) {
        return 'No limit';
    }
    const [whole, pence = '00'] = limit.split('.');
    return `£${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${pence.padEnd(2, '0')}`;
}

const ROLE_STYLES: Record<string, string> = {
    owner: 'bg-violet-50 text-violet-800 ring-violet-200',
    buyer: 'bg-sky-50 text-sky-800 ring-sky-200',
    approver: 'bg-amber-50 text-amber-800 ring-amber-200',
    viewer: 'bg-slate-50 text-slate-700 ring-slate-200',
};

const STATE_STYLES: Record<string, string> = {
    Invited: 'bg-amber-50 text-amber-800 ring-amber-200',
    Accepted: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    Expired: 'bg-slate-50 text-slate-600 ring-slate-200',
    Revoked: 'bg-red-50 text-red-700 ring-red-200',
    active: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    suspended: 'bg-red-50 text-red-700 ring-red-200',
    closed: 'bg-slate-50 text-slate-600 ring-slate-200',
};

export function Pill({ tone, children }: { tone: string; children: ReactNode }) {
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone)}>{children}</span>
    );
}

export function RolePill({ role }: { role: string }) {
    return <Pill tone={ROLE_STYLES[role] ?? ROLE_STYLES.viewer}>{roleLabel(role)}</Pill>;
}

export function StatePill({ state }: { state: string }) {
    const label = state.charAt(0).toUpperCase() + state.slice(1);
    return <Pill tone={STATE_STYLES[state] ?? STATE_STYLES.Expired}>{label}</Pill>;
}

export function Avatar({ name }: { name: string }) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

    return (
        <span aria-hidden className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
            {initials || '?'}
        </span>
    );
}

/** A small accessible modal: focus moves in, Escape and the backdrop close it. */
export function Dialog({ open, title, description, onClose, children }: { open: boolean; title: string; description?: ReactNode; onClose: () => void; children: ReactNode }) {
    const titleId = useId();
    const panel = useRef<HTMLDivElement>(null);
    // Kept in a ref so re-renders while typing never re-run the focus effect.
    const close = useRef(onClose);
    close.current = onClose;

    useEffect(() => {
        if (!open) {
            return;
        }
        const previous = document.activeElement as HTMLElement | null;
        panel.current?.querySelector<HTMLElement>('input:not([type=radio]), select, button')?.focus();
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close.current();
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('keydown', onKey);
            previous?.focus();
        };
    }, [open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div ref={panel} role="dialog" aria-modal="true" aria-labelledby={titleId} className="max-h-[90vh] w-full overflow-y-auto rounded-t-xl bg-background p-5 shadow-xl sm:max-w-lg sm:rounded-xl sm:p-6">
                <div className="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 id={titleId} className="text-lg font-semibold tracking-tight">
                            {title}
                        </h2>
                        {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Close">
                        <X className="size-5" aria-hidden />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

export function MembershipFields({
    role,
    orderLimit,
    requiresApproval,
    errors,
    onChange,
    roleLocked = false,
}: {
    role: string;
    orderLimit: string;
    requiresApproval: boolean;
    errors: Partial<Record<string, string>>;
    onChange: (key: 'role' | 'order_limit' | 'requires_approval', value: string | boolean) => void;
    roleLocked?: boolean;
}) {
    const roleId = useId();

    return (
        <div className="space-y-4">
            <fieldset className="space-y-2">
                <legend id={roleId} className="text-sm font-medium">
                    Role
                </legend>
                <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-labelledby={roleId}>
                    {ROLES.map((r) => (
                        <label
                            key={r.value}
                            className={cn(
                                'flex items-start gap-2.5 rounded-lg border p-3 text-sm transition-colors',
                                role === r.value ? 'border-foreground bg-muted/60' : 'hover:bg-muted/40',
                                roleLocked && role !== r.value ? 'cursor-not-allowed opacity-50 hover:bg-transparent' : 'cursor-pointer',
                            )}
                        >
                            <input
                                type="radio"
                                name={`${roleId}-role`}
                                value={r.value}
                                checked={role === r.value}
                                disabled={roleLocked && role !== r.value}
                                onChange={() => onChange('role', r.value)}
                                className="mt-0.5 accent-primary"
                            />
                            <span>
                                <span className="block font-medium">{r.label}</span>
                                <span className="block text-xs text-muted-foreground">{r.help}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {roleLocked && <p className="text-xs text-muted-foreground">This is the only active owner. Make someone else an owner first to change this role.</p>}
                {errors.role && <p className="text-xs text-red-700">{errors.role}</p>}
            </fieldset>
            <Field
                label="Order limit (£)"
                inputMode="decimal"
                placeholder="No limit"
                value={orderLimit}
                onChange={(e) => onChange('order_limit', e.target.value)}
                error={errors.order_limit}
                hint="The most a single order may total. Leave empty for no limit."
            />
            <Checkbox label="Orders need approval before they are placed" checked={requiresApproval} onChange={(checked) => onChange('requires_approval', checked)} />
        </div>
    );
}

export function PendingInvitations({ invitations }: { invitations: PendingInvitation[] }) {
    return (
        <ul className="divide-y text-sm">
            {invitations.map((invitation) => (
                <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <span>
                        <strong>{invitation.company}</strong> invited you as {roleLabel(invitation.role).toLowerCase()}.
                    </span>
                    <Button className="h-11 md:h-9" onClick={() => router.post(`/account/invitations/${invitation.id}/accept`, {}, { preserveScroll: true })}>
                        Accept
                    </Button>
                </li>
            ))}
        </ul>
    );
}

export interface MenuAction {
    label: string;
    icon: ReactNode;
    onSelect: () => void;
    danger?: boolean;
    disabled?: boolean;
    hint?: string;
}

/** A row's "⋯" menu: opens on click, closes on Escape, outside click or selection. */
export function RowMenu({ label, actions }: { label: string; actions: MenuAction[] }) {
    const [open, setOpen] = useState(false);
    const wrapper = useRef<HTMLDivElement>(null);
    const menuId = useId();

    useEffect(() => {
        if (!open) {
            return;
        }
        const onDown = (e: MouseEvent) => wrapper.current && !wrapper.current.contains(e.target as Node) && setOpen(false);
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        wrapper.current?.querySelector<HTMLElement>('[role=menuitem]:not([disabled])')?.focus();
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div ref={wrapper} className="relative inline-block text-left">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={menuId}
                aria-label={label}
                onClick={() => setOpen((v) => !v)}
                className="inline-flex size-11 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground md:size-8"
            >
                <MoreHorizontal className="size-4" aria-hidden />
            </button>
            {open && (
                <div id={menuId} role="menu" className="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-lg border bg-background p-1 shadow-lg">
                    {actions.map((action) => (
                        <button
                            key={action.label}
                            type="button"
                            role="menuitem"
                            disabled={action.disabled}
                            title={action.disabled ? action.hint : undefined}
                            onClick={() => {
                                setOpen(false);
                                action.onSelect();
                            }}
                            className={cn(
                                'flex w-full items-start gap-2 rounded-md px-2.5 py-2 text-left text-sm',
                                action.disabled ? 'cursor-not-allowed text-muted-foreground' : action.danger ? 'text-red-700 hover:bg-red-50' : 'hover:bg-muted',
                            )}
                        >
                            <span className="mt-0.5 shrink-0">{action.icon}</span>
                            <span>
                                {action.label}
                                {action.disabled && action.hint && <span className="block text-xs font-normal">{action.hint}</span>}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
