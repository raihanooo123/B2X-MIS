/**
 * Find my order (05.15 §6.2): order number and email → a fresh link to the
 * order page, by email. The answer is the same whether or not they match.
 */
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

export default function Lookup({ status }: { status: string | null }) {
    const form = useForm({ order_number: '', email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/orders/lookup');
    };

    return (
        <AuthLayout
            title="Find my order"
            description="Enter your order number and the email you ordered with. We will email you a link to your order, which works for 90 days."
            status={status}
            footer={
                <span>
                    Have an account? <Link href="/login" className="underline underline-offset-4">Sign in</Link> to see all your orders.
                </span>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Order number"
                    autoComplete="off"
                    required
                    autoFocus
                    value={form.data.order_number}
                    onChange={(e) => form.setData('order_number', e.target.value)}
                    error={form.errors.order_number}
                />
                <Field label="Email" type="email" autoComplete="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
                <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                    Email me a link
                </Button>
            </form>
        </AuthLayout>
    );
}
