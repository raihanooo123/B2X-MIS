/**
 * Two-factor enrolment (05.13 §12). Mandatory for staff — they arrive here
 * and cannot leave until it is on; optional for everyone else. Once it is
 * on, it is managed from the account page (Account/Index).
 *
 *   1. add the key to an authenticator app — scan the QR code, open the
 *      otpauth link on a phone, or type or copy the key;
 *   2. enter a code to prove the app has it;
 *   3. save the recovery codes and tick that they are saved — only then
 *      is 2FA switched on.
 *
 * The QR code encodes the otpauth:// URI and is drawn in the browser
 * (qrcode.react): the key is never sent to an image service.
 */
import { router, useForm, usePage } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { type FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { CopyButton, SaveRecoveryCodes } from '@/components/auth/twoFactor';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types/shared';

interface Props {
    is_staff: boolean;
    secret: string;
    otpauth_uri: string;
    pending_codes: string[] | null;
}

export default function TwoFactorSetup(props: Props) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <AuthLayout
            title="Set up two-factor authentication"
            description={
                props.is_staff
                    ? 'Staff accounts must use two-factor authentication. Set it up to continue.'
                    : 'Protect your account with a code from your phone as well as your password.'
            }
            status={flash.status}
            wide={props.pending_codes === null}
        >
            {props.pending_codes ? (
                <Step n={3} title="Save your recovery codes">
                    <SaveRecoveryCodes
                        codes={props.pending_codes}
                        action="/two-factor/setup/complete"
                        submitLabel="Turn on two-factor authentication"
                        intro="If you lose your phone, each of these codes lets you sign in once. This is the only time they are shown — copy or download them now."
                    />
                </Step>
            ) : (
                <AddKey secret={props.secret} otpauthUri={props.otpauth_uri} />
            )}
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

    if (secret === '') {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                Your setup key could not be loaded. Reload the page; if this keeps happening, contact us.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            <Step n={1} title="Add this account to your authenticator app">
                <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
                    <div className="shrink-0 self-center rounded-lg border bg-white p-3 sm:self-start">
                        <QRCodeSVG value={otpauthUri} size={176} level="M" marginSize={1} bgColor="#ffffff" fgColor="#000000" title="QR code for your authenticator app" />
                    </div>
                    <div className="min-w-0 flex-1 space-y-3 text-sm">
                        <p className="text-muted-foreground">
                            Scan the code with your authenticator app — Apple Passwords, Google Authenticator, Microsoft Authenticator, 1Password and others all work.
                        </p>
                        <Button asChild variant="outline" className="h-11 w-full sm:w-auto">
                            <a href={otpauthUri}>
                                <Smartphone /> On this phone? Open in authenticator app
                            </a>
                        </Button>
                        <div className="space-y-1.5">
                            <label htmlFor="setup-key" className="block text-muted-foreground">
                                Can't scan? Enter this key by hand:
                            </label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <input
                                    id="setup-key"
                                    readOnly
                                    value={grouped}
                                    onFocus={(e) => e.currentTarget.select()}
                                    spellCheck={false}
                                    className="h-11 min-w-0 flex-1 rounded-md border bg-muted px-3 font-mono text-sm tracking-wide md:h-9"
                                />
                                <CopyButton text={secret} label="Copy key" />
                            </div>
                        </div>
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
                <p className="text-xs text-muted-foreground">
                    This key stays the same until you set it up, even if you sign out. Codes not matching?{' '}
                    <button
                        type="button"
                        className="font-medium text-foreground underline underline-offset-4"
                        onClick={() => router.post('/two-factor/setup/reset', {}, { preserveScroll: true })}
                    >
                        Start again with a new key
                    </button>{' '}
                    — then remove the old entry from your app.
                </p>
            </Step>
        </div>
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
