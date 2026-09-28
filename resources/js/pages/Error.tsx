/**
 * Page errors in the storefront's design (05.15 §3.2), rendered by
 * bootstrap/app.php outside local and test runs. A 404 offers search.
 */
import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useId, useRef, type FormEvent } from 'react';

import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { storefrontLinks } from '@/lib/storefront/links';

const MESSAGES: Record<number, { title: string; text: string }> = {
    403: { title: 'You don’t have access to this page', text: 'If you think you should, sign in with the right account or contact us.' },
    404: { title: 'We can’t find that page', text: 'It may have moved, or the link may be out of date. Try a search instead.' },
    429: { title: 'Too many requests', text: 'Please wait a moment and try again.' },
    500: { title: 'Something went wrong on our side', text: 'Please try again in a few minutes. If it keeps happening, contact us.' },
    503: { title: 'We’ll be back shortly', text: 'The site is down for a short update. Please try again in a few minutes.' },
};

export default function ErrorPage({ status, shell }: { status: number; shell: ShellProps | null }) {
    const message = MESSAGES[status] ?? MESSAGES[500];

    return (
        <StorefrontLayout title={message.title} shell={shell}>
            <div className="mx-auto flex max-w-xl flex-col items-center px-4 py-20 text-center">
                <p className="text-sm font-semibold tabular-nums text-primary">Error {status}</p>
                <h1 className="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">{message.title}</h1>
                <p className="mt-3 text-muted-foreground">{message.text}</p>
                {status === 404 && <ErrorSearch />}
                <Link href={storefrontLinks.home()} className="mt-6 inline-flex min-h-11 items-center rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground hover:bg-primary/90">
                    Back to the home page
                </Link>
            </div>
        </StorefrontLayout>
    );
}

function ErrorSearch() {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const q = input.current?.value.trim() ?? '';
        if (q !== '') {
            router.visit(storefrontLinks.search(q));
        }
    };

    return (
        <form role="search" onSubmit={submit} className="relative mt-6 w-full">
            <label htmlFor={id} className="sr-only">
                Search products
            </label>
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <input ref={input} id={id} type="search" placeholder="Search products" className="h-11 w-full rounded-lg border pl-9 pr-4 outline-none focus:border-ring focus:ring-2 focus:ring-ring/30" />
        </form>
    );
}
