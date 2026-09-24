/**
 * The frame for every sign-in, registration and account-security page
 * (05.13): a single centred column, the page title, an optional status
 * message (flashed by the server) and the form.
 */
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface AuthLayoutProps {
    title: string;
    description?: ReactNode;
    status?: string | null;
    wide?: boolean;
    children: ReactNode;
    footer?: ReactNode;
}

export function AuthLayout({ title, description, status, wide = false, children, footer }: AuthLayoutProps) {
    return (
        <>
            <Head title={title} />
            <div className="flex min-h-screen flex-col items-center bg-muted/40 px-4 py-8 sm:justify-center sm:py-12">
                <Link href="/order-pad" className="mb-6 text-sm font-semibold tracking-tight text-foreground">
                    B2X Wholesale
                </Link>
                <main className={cn('w-full rounded-lg border bg-background p-6 shadow-sm sm:p-8', wide ? 'max-w-2xl' : 'max-w-md')}>
                    <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                    {description && <div className="mt-1.5 text-sm text-muted-foreground">{description}</div>}
                    {status && (
                        <p role="status" className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                            {status}
                        </p>
                    )}
                    <div className="mt-6">{children}</div>
                </main>
                {footer && <div className="mt-4 text-center text-sm text-muted-foreground">{footer}</div>}
            </div>
        </>
    );
}
