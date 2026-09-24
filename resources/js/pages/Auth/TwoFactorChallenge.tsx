/**
 * The second step of sign-in (05.13 §12.3): a code from the authenticator
 * app, or one of the recovery codes.
 */
import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/two-factor-challenge', { onFinish: () => form.reset('code') });
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={recovery ? 'Enter one of the recovery codes you saved when you set up two-factor authentication. Each code works once.' : 'Enter the 6-digit code from your authenticator app.'}
            footer={<Link href="/login" className="underline underline-offset-4">Start again</Link>}
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                {recovery ? (
                    <Field
                        key="recovery"
                        label="Recovery code"
                        autoComplete="off"
                        autoCapitalize="off"
                        spellCheck={false}
                        placeholder="xxxxx-xxxxx"
                        required
                        autoFocus
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        error={form.errors.code}
                    />
                ) : (
                    <Field
                        key="totp"
                        label="Authentication code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]*"
                        maxLength={6}
                        required
                        autoFocus
                        className="[&_input]:text-center [&_input]:font-mono [&_input]:text-lg [&_input]:tracking-[0.4em]"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                        error={form.errors.code}
                    />
                )}
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    Verify
                </Button>
                <button
                    type="button"
                    className="min-h-11 w-full text-sm text-muted-foreground underline-offset-4 hover:underline"
                    onClick={() => {
                        setRecovery(!recovery);
                        form.reset('code');
                        form.clearErrors();
                    }}
                >
                    {recovery ? 'Use your authenticator app instead' : 'Lost your phone? Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
