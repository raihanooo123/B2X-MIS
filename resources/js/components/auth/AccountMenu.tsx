/**
 * The order pad's account strip (05.13 §6.3): sign-in links for guests;
 * for a signed-in user, who they are, the company they are ordering for
 * (with a switch when they have several), security settings and sign-out.
 * An unconfirmed email is flagged here, since checkout will need it.
 */
import { Link, usePage } from '@inertiajs/react';
import { Building2, LogOut, MailWarning, ShieldCheck, ShoppingCart } from 'lucide-react';

import type { SharedProps } from '@/types/shared';

export function AccountMenu() {
    const { auth } = usePage<SharedProps>().props;
    const linkClass = 'inline-flex min-h-11 items-center gap-1.5 rounded-md px-2 text-muted-foreground hover:text-foreground md:min-h-8';

    if (auth === null) {
        return (
            <nav aria-label="Account" className="flex items-center gap-1 text-sm">
                <Link href="/cart" className={linkClass}>
                    <ShoppingCart className="size-4" aria-hidden /> Cart
                </Link>
                <Link href="/login" className={linkClass}>
                    Sign in
                </Link>
                <Link href="/register?type=trade" className={`${linkClass} font-medium text-foreground`}>
                    Apply for trade prices
                </Link>
            </nav>
        );
    }

    return (
        <nav aria-label="Account" className="flex flex-wrap items-center gap-x-1 text-sm">
            {auth.company && (
                <span className="inline-flex items-center gap-1.5 px-2 font-medium">
                    <Building2 className="size-4 text-muted-foreground" aria-hidden />
                    <span className="sr-only">Ordering for </span>
                    {auth.company.name}
                    {auth.can_switch_company && (
                        <Link href="/choose-company" className="ml-1 text-xs font-normal text-muted-foreground underline underline-offset-4 hover:text-foreground">
                            Switch
                        </Link>
                    )}
                </span>
            )}
            <Link href="/cart" className={linkClass}>
                <ShoppingCart className="size-4" aria-hidden /> Cart
            </Link>
            {!auth.user.email_verified && (
                <Link href="/email/verify" className={`${linkClass} text-amber-800`}>
                    <MailWarning className="size-4" aria-hidden /> Confirm email
                </Link>
            )}
            <Link href="/account" className={linkClass} title="Your account and security">
                <ShieldCheck className="size-4" aria-hidden />
                <span className="hidden sm:inline">{auth.user.first_name}</span>
                <span className="sr-only sm:hidden">Your account</span>
            </Link>
            <Link href="/logout" method="post" as="button" className={linkClass}>
                <LogOut className="size-4" aria-hidden /> Sign out
            </Link>
        </nav>
    );
}
