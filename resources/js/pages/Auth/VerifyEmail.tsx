/**
 * Email confirmation status (05.13 §11). Signed-in users can browse and
 * build a cart while unconfirmed; this page resends the link.
 */
import { Link, useForm } from '@inertiajs/react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Button } from '@/components/ui/button';

export default function VerifyEmail({ email, verified, status }: { email: string; verified: boolean; status: string | null }) {
    const form = useForm({});

    return (
        <AuthLayout
            title={verified ? 'Email confirmed' : 'Confirm your email address'}
            status={status}
            footer={<Link href="/order-pad" className="underline underline-offset-4">Go to the order pad</Link>}
        >
            {verified ? (
                <p className="text-sm">{email} is confirmed.</p>
            ) : (
                <div className="space-y-4">
                    <p className="text-sm">
                        We sent a confirmation link to <strong>{email}</strong>. It expires after 24 hours.
                    </p>
                    <Button className="h-11 w-full" variant="outline" disabled={form.processing} onClick={() => form.post('/email/verification-notification')}>
                        Send a new link
                    </Button>
                </div>
            )}
        </AuthLayout>
    );
}
