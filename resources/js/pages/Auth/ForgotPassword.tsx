/**
 * Request a reset link (05.13 §10). The answer is the same whether or not
 * the address has an account.
 */
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

export default function ForgotPassword({ status }: { status: string | null }) {
    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <AuthLayout
            title="Reset your password"
            description="Enter your email address and we will send you a link to choose a new password. The link works once, for 60 minutes."
            status={status}
            footer={<Link href="/login" className="underline underline-offset-4">Back to sign in</Link>}
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Email"
                    type="email"
                    autoComplete="email"
                    required
                    autoFocus
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    Send reset link
                </Button>
            </form>
        </AuthLayout>
    );
}
