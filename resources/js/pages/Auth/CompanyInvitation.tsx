/**
 * The page an invitation email links to (05.13 §9.2). It is the same page
 * whether or not the invited address already has an account, so it never
 * reveals which (no email, name or "account exists" flag is sent to the
 * browser). A new person chooses a password here; someone who already has
 * an account signs in and comes back to accept.
 */
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Field, FormError } from '@/components/auth/Field';
import { clearPasswords } from '@/components/auth/passwordInputs';
import { PASSWORD_HINT } from '@/components/auth/passwordHint';
import { Button } from '@/components/ui/button';

interface InvitationProps {
    invitation: { company: string; role: string };
    signed_in: boolean;
    refusal: string | null;
    action: string;
    sign_in_url: string;
}

const ROLE_LABELS: Record<string, string> = {
    owner: 'an owner',
    buyer: 'a buyer',
    approver: 'an approver',
    viewer: 'a viewer',
};

export default function CompanyInvitation({ invitation, signed_in, refusal, action, sign_in_url }: InvitationProps) {
    const form = useForm({ password: '', password_confirmation: '' });
    const role = ROLE_LABELS[invitation.role] ?? invitation.role;

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const element = e.currentTarget;
        const values = new FormData(element);
        if (!signed_in) {
            form.transform(() => ({
                password: String(values.get('password') ?? ''),
                password_confirmation: String(values.get('password_confirmation') ?? ''),
            }));
        }
        form.post(action, {
            onFinish: () => {
                form.reset('password', 'password_confirmation');
                clearPasswords(element);
            },
        });
    };

    return (
        <AuthLayout
            title={`Join ${invitation.company}`}
            description={
                <>
                    You have been invited to order for <strong className="text-foreground">{invitation.company}</strong> as {role}.
                </>
            }
        >
            {refusal ? (
                <div className="space-y-4">
                    <FormError message={refusal} />
                    <Button asChild variant="outline" className="h-11 w-full">
                        <Link href="/order-pad">Go to the order pad</Link>
                    </Button>
                </div>
            ) : signed_in ? (
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                        Accept invitation
                    </Button>
                </form>
            ) : (
                <div className="space-y-6">
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold">Already have an account?</h2>
                        <Button asChild variant="outline" className="h-11 w-full">
                            <a href={sign_in_url}>Sign in to accept</a>
                        </Button>
                    </section>

                    <section className="space-y-3 border-t pt-5">
                        <h2 className="text-sm font-semibold">New here? Choose a password</h2>
                        <form onSubmit={submit} className="space-y-4" noValidate>
                            <Field
                                label="Password"
                                type="password"
                                name="password"
                                autoComplete="new-password"
                                required
                                minLength={12}
                                defaultValue=""
                                error={form.errors.password}
                                hint={PASSWORD_HINT}
                            />
                            <Field
                                label="Confirm password"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                                defaultValue=""
                                error={form.errors.password_confirmation}
                            />
                            <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                                Create account and accept
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                If this address already has an account, you will be asked to sign in instead. Your existing password is never changed here.
                            </p>
                        </form>
                    </section>
                </div>
            )}
        </AuthLayout>
    );
}
