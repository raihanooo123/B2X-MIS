/**
 * Two-factor authentication (05.13 §12): enrolment in three steps, then
 * managing it. Mandatory for staff — they arrive here and cannot leave
 * until it is on; optional for everyone else.
 *
 *   1. add the key to an authenticator app (the otpauth link opens one
 *      directly on a phone; the key can be typed into any);
 *   2. enter a code to prove the app has it;
 *   3. save the recovery codes and confirm — only then is 2FA switched on.
 *
 * No QR code yet: rendering one needs a library outside the approved
 * stack. The key and the otpauth link cover desktop and phone.
 */
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Download, ShieldCheck, Smartphone } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Checkbox, Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types/shared';

type Props =
    | { enabled: false; is_staff: boolean; secret: string; otpauth_uri: string; pending_codes: string[] | null }
    | { enabled: true; is_staff: boolean; remaining_codes: number; low_watermark: number; new_codes: string[] | null };

export default function TwoFactorSetup(props: Props) {
    const { flash } = usePage<SharedProps>().props;

    if (props.enabled) {
        return <Manage {...props} status={flash.status} />;
    }

    return (
        <AuthLayout
            title="Set up two-factor authentication"
            description={
                props.is_staff
                    ? 'Staff accounts must use two-factor authentication. Set it up to continue.'
                    : 'Protect your account with a code from your phone as well as your password.'
            }
            status={flash.status}
        >
            {props.pending_codes ? <SaveCodes codes={props.pending_codes} /> : <AddKey secret={props.secret} otpauthUri={props.otpauth_uri} />}
        </AuthLayout>
    );
}

function AddKey({ secret, otpauthUri }: { secret: string; otpauthUri: string }) {
    const form = useForm({ code: '' });
    const grouped = secret.match(/.{1,4}/g)?.join(' ') ?? secret;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/two-factor/setup/confirm', { onFinish: () => form.reset('code') });
    };

    return (
        <div className="space-y-6">
            <Step n={1} title="Add this account to your authenticator app">
                <p className="text-sm text-muted-foreground">
                    Use any authenticator app — Google Authenticator, Microsoft Authenticator, 1Password and others all work.
                </p>
                <Button asChild variant="outline" className="h-11 w-full sm:w-auto">
                    <a href={otpauthUri}>
                        <Smartphone /> Open in authenticator app
                    </a>
                </Button>
                <div>
                    <p className="text-sm text-muted-foreground">Or enter this key by hand:</p>
                    <div className="mt-1.5 flex items-center gap-2">
                        <code className="flex-1 break-all rounded-md border bg-muted px-3 py-2 font-mono text-sm tracking-wide">{grouped}</code>
                        <CopyButton text={secret} label="Copy key" />
                    </div>
                </div>
            </Step>

            <Step n={2} title="Enter the 6-digit code it shows">
                <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-start" noValidate>
                    <Field
                        label="Code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        required
                        className="flex-1 [&_input]:font-mono [&_input]:tracking-[0.3em]"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                        error={form.errors.code}
                    />
                    <Button type="submit" className="h-11 sm:mt-7 sm:h-10" disabled={form.processing || form.data.code.length !== 6}>
                        Verify
                    </Button>
                </form>
            </Step>
        </div>
    );
}

function SaveCodes({ codes }: { codes: string[] }) {
    const form = useForm({ saved: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/two-factor/setup/complete');
    };

    return (
        <form onSubmit={submit} className="space-y-5" noValidate>
            <Step n={3} title="Save your recovery codes">
                <p className="text-sm text-muted-foreground">
                    If you lose your phone, each of these codes lets you sign in once. Keep them somewhere safe and separate from your phone. This is the only time
                    they are shown.
                </p>
                <CodeList codes={codes} />
            </Step>
            <Checkbox label="I have saved my recovery codes." checked={form.data.saved} onChange={(v) => form.setData('saved', v)} error={form.errors.saved} />
            <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                Turn on two-factor authentication
            </Button>
        </form>
    );
}

function Manage(props: Extract<Props, { enabled: true }> & { status: string | null }) {
    const regenerate = useForm({ password: '' });
    const disable = useForm({ password: '' });
    const low = props.remaining_codes <= props.low_watermark;

    return (
        <AuthLayout title="Two-factor authentication" status={props.status}>
            <div className="space-y-8">
                <p className="flex items-center gap-2 text-sm">
                    <ShieldCheck className="size-5 text-emerald-600" aria-hidden /> Two-factor authentication is on.
                </p>

                {props.new_codes && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold">Your new recovery codes</h2>
                        <p className="text-sm text-muted-foreground">Your old codes no longer work. Save these now — they are not shown again.</p>
                        <CodeList codes={props.new_codes} />
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">Recovery codes</h2>
                    <p className={low ? 'text-sm text-amber-800' : 'text-sm text-muted-foreground'}>
                        {props.remaining_codes} unused {props.remaining_codes === 1 ? 'code' : 'codes'} left.
                        {low && ' Generate a new set so you are not locked out if you lose your phone.'}
                    </p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            regenerate.post('/two-factor/recovery-codes', { onFinish: () => regenerate.reset('password') });
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
                            value={regenerate.data.password}
                            onChange={(e) => regenerate.setData('password', e.target.value)}
                            error={regenerate.errors.password}
                        />
                        <Button type="submit" variant="outline" className="h-11 sm:mt-7 sm:h-10" disabled={regenerate.processing}>
                            Generate new codes
                        </Button>
                    </form>
                </section>

                {props.is_staff ? (
                    <p className="text-xs text-muted-foreground">Two-factor authentication is required for staff accounts and cannot be turned off.</p>
                ) : (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold">Turn off</h2>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                disable.delete('/two-factor', { onFinish: () => disable.reset('password') });
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
                            <Button type="submit" variant="destructive" className="h-11 sm:mt-7 sm:h-10" disabled={disable.processing}>
                                Turn off
                            </Button>
                        </form>
                    </section>
                )}

                <Button variant="ghost" className="h-11 w-full" onClick={() => router.visit('/order-pad')}>
                    Back to the order pad
                </Button>
            </div>
        </AuthLayout>
    );
}

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
    return (
        <section className="space-y-3">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <span className="flex size-6 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground" aria-hidden>
                    {n}
                </span>
                {title}
            </h2>
            {children}
        </section>
    );
}

function CodeList({ codes }: { codes: string[] }) {
    const text = codes.join('\n');

    const download = () => {
        const url = URL.createObjectURL(new Blob([`B2X Wholesale recovery codes\n\n${text}\n`], { type: 'text/plain' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'b2x-recovery-codes.txt';
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-3">
            <ul className="grid grid-cols-2 gap-2 rounded-md border bg-muted p-3 font-mono text-sm" aria-label="Recovery codes">
                {codes.map((c) => (
                    <li key={c}>{c}</li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-2">
                <CopyButton text={text} label="Copy codes" />
                <Button type="button" variant="outline" className="h-11 md:h-9" onClick={download}>
                    <Download /> Download
                </Button>
            </div>
        </div>
    );
}

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            className="h-11 md:h-9"
            onClick={() => {
                void navigator.clipboard?.writeText(text).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 2000);
                });
            }}
        >
            {copied ? <Check /> : <Copy />} {copied ? 'Copied' : label}
        </Button>
    );
}
