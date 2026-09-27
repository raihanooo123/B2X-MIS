/**
 * Choose a new password from a reset link (05.13 §10) — also how a new
 * staff member sets their first password (§5.3). Signing in afterwards
 * still asks for the second factor if it is on.
 */
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { clearPasswords } from '@/components/auth/passwordInputs';
import { PASSWORD_HINT } from '@/components/auth/passwordHint';
import { Button } from '@/components/ui/button';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const element = e.currentTarget;
        const values = new FormData(element);
        form.transform((data) => ({ ...data, password: String(values.get('password') ?? ''), password_confirmation: String(values.get('password_confirmation') ?? '') }));
        form.post('/reset-password', { onFinish: () => { form.reset('password', 'password_confirmation'); clearPasswords(element); } });
    };

    return (
        <AuthLayout title="Choose a new password">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Email"
                    type="email"
                    autoComplete="username"
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />
                <Field
                    label="New password"
                    type="password"
                    name="password"
                    autoComplete="new-password"
                    required
                    autoFocus
                    minLength={12}
                    defaultValue=""
                    error={form.errors.password}
                    hint={PASSWORD_HINT}
                />
                <Field
                    label="Confirm new password"
                    type="password"
                    name="password_confirmation"
                    autoComplete="new-password"
                    required
                    defaultValue=""
                    error={form.errors.password_confirmation}
                />
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    Save password and sign in
                </Button>
            </form>
        </AuthLayout>
    );
}
