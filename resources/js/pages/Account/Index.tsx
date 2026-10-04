/**
 * The account page: details, the company being ordered for, and security
 * — two-factor authentication and its recovery codes (05.13 §12).
 *
 * Regenerating recovery codes is two steps: re-enter the password, then
 * save the new set and tick that it is saved. Until then the current
 * codes keep working, so abandoning half-way never leaves anyone holding
 * codes they never saw.
 */
import { Link, router, useForm } from '@inertiajs/react';
import { KeyRound, ShieldAlert, ShieldCheck, Users } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import { Field } from '@/components/auth/Field';
import { SaveRecoveryCodes } from '@/components/auth/twoFactor';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { PendingInvitations, type PendingInvitation } from './components/team';

import type { ShellProps } from '@/components/storefront/StorefrontLayout';

interface AccountProps {
    shell: ShellProps | null;
    user: { name: string; email: string; email_verified: boolean };
    company: { name: string; account_code: string } | null;
    team: { members: number; pending_invitations: number } | null;
    invitations: PendingInvitation[];
    two_factor: {
        enabled: boolean;
        required: boolean;
        remaining_codes: number;
        low_watermark: number;
        pending_codes: string[] | null;
    };
    status: string | null;
}

export default function AccountIndex({ user, company, team, invitations, two_factor: tf, status, shell }: AccountProps) {
    return (
        <AccountLayout shell={shell} title="Account & security" description="Your details, and how you sign in." status={status}>
            <div className="space-y-6">
                {invitations.length > 0 && (
                    <AccountCard title="Invitations for you" description="Accept to start ordering for that company.">
                        <div className="px-5 py-4">
                            <PendingInvitations invitations={invitations} />
                        </div>
                    </AccountCard>
                )}

                <AccountCard title="Details">
                    <dl className="divide-y text-sm">
                        <Row label="Name">{user.name}</Row>
                        <Row label="Email">
                            {user.email}
                            {!user.email_verified && (
                                <Link href="/email/verify" className="ml-2 text-xs font-medium text-amber-800 underline">
                                    Not confirmed — resend link
                                </Link>
                            )}
                        </Row>
                        {company && (
                            <Row label="Ordering for">
                                {company.name} <span className="text-muted-foreground">· {company.account_code}</span>
                            </Row>
                        )}
                    </dl>
                </AccountCard>

                {team && company && (
                    <AccountCard>
                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm">
                            <p className="flex items-center gap-3">
                                <span className="inline-flex size-9 items-center justify-center rounded-full bg-muted">
                                    <Users className="size-4 text-muted-foreground" aria-hidden />
                                </span>
                                <span>
                                    <span className="block font-medium">Team</span>
                                    <span className="text-muted-foreground">
                                        {team.members} {team.members === 1 ? 'person' : 'people'} can order for {company.name}
                                        {team.pending_invitations > 0 && ` · ${team.pending_invitations} invitation${team.pending_invitations === 1 ? '' : 's'} waiting`}
                                    </span>
                                </span>
                            </p>
                            <Button asChild variant="outline" className="h-11 md:h-9">
                                <Link href="/account/team">Manage team</Link>
                            </Button>
                        </div>
                    </AccountCard>
                )}

                <AccountCard title="Two-factor authentication" description="A code from your phone, as well as your password.">
                    <div className="px-5 py-4">{tf.enabled ? <TwoFactorOn tf={tf} /> : <TwoFactorOff required={tf.required} />}</div>
                </AccountCard>
            </div>
        </AccountLayout>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1 px-5 py-3 sm:grid-cols-[10rem_1fr] sm:gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function TwoFactorOff({ required }: { required: boolean }) {
    return (
        <div className="space-y-3 text-sm">
            <p className="flex items-center gap-2">
                <ShieldAlert className="size-5 text-amber-600" aria-hidden /> Two-factor authentication is off.
            </p>
            <p className="text-muted-foreground">
                {required ? 'It is required for staff accounts.' : 'Add a code from your phone to your password, so a stolen password alone cannot sign in.'}
            </p>
            <Button asChild className="h-11">
                <Link href="/two-factor/setup">Set up two-factor authentication</Link>
            </Button>
        </div>
    );
}

function TwoFactorOn({ tf }: { tf: AccountProps['two_factor'] }) {
    const regenerate = useForm({ password: '' });
    const disable = useForm({ password: '' });
    const low = tf.remaining_codes <= tf.low_watermark;

    const submitRegenerate = (e: FormEvent) => {
        e.preventDefault();
        regenerate.post('/two-factor/recovery-codes', { preserveScroll: true, onFinish: () => regenerate.reset('password') });
    };

    return (
        <div className="space-y-6 text-sm">
            <p className="flex items-center gap-2">
                <ShieldCheck className="size-5 text-emerald-600" aria-hidden /> Two-factor authentication is on.
            </p>

            <section className="space-y-3">
                <h3 className="flex items-center gap-2 font-semibold">
                    <KeyRound className="size-4" aria-hidden /> Recovery codes
                </h3>

                {tf.pending_codes ? (
                    <SaveRecoveryCodes
                        codes={tf.pending_codes}
                        action="/two-factor/recovery-codes/confirm"
                        submitLabel="Use these new codes"
                        intro={
                            <>
                                Your new recovery codes are below. <strong className="text-foreground">Your current codes keep working until you tick the box and confirm</strong>{' '}
                                — then only these will.
                            </>
                        }
                        secondary={
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11"
                                onClick={() => router.post('/two-factor/recovery-codes/cancel', {}, { preserveScroll: true })}
                            >
                                Keep my current codes
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <p className={cn(low ? 'text-amber-800' : 'text-muted-foreground')}>
                            {tf.remaining_codes} unused {tf.remaining_codes === 1 ? 'code' : 'codes'} left.
                            {low && ' Regenerate them so you are not locked out if you lose your phone.'}
                        </p>
                        <form onSubmit={submitRegenerate} className="flex flex-col gap-3 sm:flex-row sm:items-start" noValidate>
                            <Field
                                label="Current password"
                                type="password"
                                autoComplete="current-password"
                                required
                                className="flex-1"
                                value={regenerate.data.password}
                                onChange={(e) => regenerate.setData('password', e.target.value)}
                                error={regenerate.errors.password}
                            />
                            <Button type="submit" variant="outline" className="h-11 sm:mt-7 sm:h-10" disabled={regenerate.processing || regenerate.data.password === ''}>
                                Regenerate recovery codes
                            </Button>
                        </form>
                    </>
                )}
            </section>

            {tf.required ? (
                <p className="text-xs text-muted-foreground">Two-factor authentication is required for staff accounts and cannot be turned off.</p>
            ) : (
                <section className="space-y-3">
                    <h3 className="font-semibold">Turn off</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            disable.delete('/two-factor', { preserveScroll: true, onFinish: () => disable.reset('password') });
                        }}
                        className="flex flex-col gap-3 sm:flex-row sm:items-start"
                        noValidate
                    >
                        <Field
                            label="Current password"
                            type="password"
                            autoComplete="current-password"
                            required
                            className="flex-1"
                            value={disable.data.password}
                            onChange={(e) => disable.setData('password', e.target.value)}
                            error={disable.errors.password}
                        />
                        <Button type="submit" variant="destructive" className="h-11 sm:mt-7 sm:h-10" disabled={disable.processing || disable.data.password === ''}>
                            Turn off
                        </Button>
                    </form>
                </section>
            )}
        </div>
    );
}
