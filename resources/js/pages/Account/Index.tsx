/**
 * The account page: details, the company being ordered for, and security
 * — two-factor authentication and its recovery codes (05.13 §12).
 *
 * Regenerating recovery codes is two steps: re-enter the password, then
 * save the new set and tick that it is saved. Until then the current
 * codes keep working, so abandoning half-way never leaves anyone holding
 * codes they never saw.
 */
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, KeyRound, ShieldAlert, ShieldCheck } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Field } from '@/components/auth/Field';
import { SaveRecoveryCodes } from '@/components/auth/twoFactor';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface AccountProps {
    user: { name: string; email: string; email_verified: boolean };
    company: { name: string; account_code: string } | null;
    two_factor: {
        enabled: boolean;
        required: boolean;
        remaining_codes: number;
        low_watermark: number;
        pending_codes: string[] | null;
    };
    status: string | null;
}

export default function AccountIndex({ user, company, two_factor: tf, status }: AccountProps) {
    return (
        <>
            <Head title="Your account" />
            <div className="mx-auto max-w-2xl px-4 py-4">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="flex items-center gap-3">
                        <Link href="/order-pad" className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
                            <ArrowLeft className="size-4" aria-hidden /> Order pad
                        </Link>
                        <h1 className="text-lg font-semibold tracking-tight">Your account</h1>
                    </div>
                    <AccountMenu />
                </header>

                {status && (
                    <p role="status" className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                        {status}
                    </p>
                )}

                <div className="space-y-6">
                    <Card title="Details">
                        <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Name</dt>
                            <dd>{user.name}</dd>
                            <dt className="text-muted-foreground">Email</dt>
                            <dd>
                                {user.email}
                                {!user.email_verified && (
                                    <Link href="/email/verify" className="ml-2 text-xs font-medium text-amber-800 underline">
                                        Not confirmed — resend link
                                    </Link>
                                )}
                            </dd>
                            {company && (
                                <>
                                    <dt className="text-muted-foreground">Ordering for</dt>
                                    <dd>
                                        {company.name} <span className="text-muted-foreground">({company.account_code})</span>
                                    </dd>
                                </>
                            )}
                        </dl>
                    </Card>

                    <Card title="Two-factor authentication">
                        {tf.enabled ? <TwoFactorOn tf={tf} /> : <TwoFactorOff required={tf.required} />}
                    </Card>
                </div>
            </div>
        </>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-lg border p-4 sm:p-5">
            <h2 className="mb-3 text-base font-semibold">{title}</h2>
            {children}
        </section>
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
