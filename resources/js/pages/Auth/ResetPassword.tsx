/**
 * Choose a new password from a reset link (05.13 §10) — also how a new
 * staff member sets their first password (§5.3). Signing in afterwards
 * still asks for the second factor if it is on.
 */
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { PASSWORD_HINT } from '@/components/auth/passwordHint';
import { Button } from '@/components/ui/button';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
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
                    autoComplete="new-password"
                    required
                    autoFocus
                    minLength={12}
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                    hint={PASSWORD_HINT}
                />
                <Field
                    label="Confirm new password"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    error={form.errors.password_confirmation}
                />
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    Save password and sign in
                </Button>
            </form>
        </AuthLayout>
    );
}
