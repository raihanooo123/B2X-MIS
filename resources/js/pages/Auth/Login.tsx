/**
 * Sign in (05.13 §6.1) — one page for everyone, staff included (the admin
 * panel has no login page of its own). Failures show one generic message;
 * a lockout says how long to wait (§6.2).
 */
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { clearPasswords } from '@/components/auth/passwordInputs';
import { Button } from '@/components/ui/button';

export default function Login({ status }: { status: string | null }) {
    const form = useForm({ email: '', password: '' });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const element = e.currentTarget;
        const values = new FormData(element);
        form.transform((data) => ({ ...data, password: String(values.get('password') ?? '') }));
        form.post('/login', { onFinish: () => { form.reset('password'); clearPasswords(element); } });
    };

    return (
        <AuthLayout
            title="Sign in"
            status={status}
            footer={
                <>
                    New here? <Link href="/register?type=trade" className="font-medium text-foreground underline underline-offset-4">Apply for a trade account</Link> or{' '}
                    <Link href="/register?type=public" className="font-medium text-foreground underline underline-offset-4">create a customer account</Link>.
                    <span className="mt-2 block">
                        Ordered as a guest? <Link href="/orders/lookup" className="font-medium text-foreground underline underline-offset-4">Find my order</Link>.
                    </span>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Email"
                    type="email"
                    name="email"
                    autoComplete="username"
                    required
                    autoFocus
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />
                <Field
                    label="Password"
                    type="password"
                    name="password"
                    autoComplete="current-password"
                    required
                    defaultValue=""
                    error={form.errors.password}
                    labelAside={
                        <Link href="/forgot-password" className="-my-3 inline-flex min-h-11 items-center text-xs text-muted-foreground underline-offset-4 hover:underline md:my-0 md:min-h-0">
                            Forgot password?
                        </Link>
                    }
                />
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    {form.processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>
        </AuthLayout>
    );
}
