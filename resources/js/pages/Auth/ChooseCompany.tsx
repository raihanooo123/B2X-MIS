/**
 * Which company to order for (05.13 §6.3) — shown after sign-in to a user
 * who belongs to more than one, and from the account menu to switch. The
 * choice lasts for this session; the next sign-in asks again.
 */
import { useForm } from '@inertiajs/react';
import { Building2, ChevronRight } from 'lucide-react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { FormError } from '@/components/auth/Field';
import { cn } from '@/lib/utils';

interface Company {
    id: string;
    name: string;
    account_code: string;
    suspended: boolean;
    current: boolean;
}

export default function ChooseCompany({ companies }: { companies: Company[] }) {
    const form = useForm({ company: '' });

    const choose = (id: string) => {
        form.transform(() => ({ company: id }));
        form.post('/choose-company');
    };

    return (
        <AuthLayout title="Which company are you ordering for?" description="Prices, your cart and your orders all follow the company you choose. You can switch at any time.">
            <FormError message={form.errors.company} />
            <ul className="space-y-2">
                {companies.map((c) => (
                    <li key={c.id}>
                        <button
                            type="button"
                            onClick={() => choose(c.id)}
                            disabled={form.processing}
                            aria-current={c.current ? 'true' : undefined}
                            className={cn(
                                'flex min-h-14 w-full items-center gap-3 rounded-md border px-4 py-3 text-left transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                c.current && 'border-primary bg-accent',
                            )}
                        >
                            <Building2 className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">{c.name}</span>
                                <span className="block text-xs text-muted-foreground">
                                    Account {c.account_code}
                                    {c.current && ' · current'}
                                    {c.suspended && ' · on hold — card payments only'}
                                </span>
                            </span>
                            <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        </button>
                    </li>
                ))}
            </ul>
        </AuthLayout>
    );
}
