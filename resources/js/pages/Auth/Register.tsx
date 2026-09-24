/**
 * Registration (05.13 §5): a trade application that also creates the
 * login (§5.1, fields from 05.2 §5.1), or a public customer account
 * (§5.2). Either way the next step is the same "check your inbox"
 * confirmation — the form never reveals whether an address already has
 * an account.
 */
import { Link, useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Checkbox, Field } from '@/components/auth/Field';
import { PASSWORD_HINT } from '@/components/auth/passwordHint';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type AccountType = 'trade' | 'public';

interface RegisterProps {
    type: AccountType;
    business_types: { value: string; label: string }[];
}

export default function Register({ type, business_types }: RegisterProps) {
    const [active, setActive] = useState<AccountType>(type);

    const choose = (next: AccountType) => {
        setActive(next);
        window.history.replaceState(window.history.state, '', `/register?type=${next}`);
    };

    return (
        <AuthLayout
            title={active === 'trade' ? 'Apply for a trade account' : 'Create a customer account'}
            description={
                active === 'trade'
                    ? 'Trade prices, volume breaks and credit terms for businesses. We review every application; until approved you can sign in and browse at our standard prices.'
                    : 'Buy at our standard prices, paying by card. You can apply for a trade account later.'
            }
            wide={active === 'trade'}
            footer={
                <>
                    Already registered? <Link href="/login" className="font-medium text-foreground underline underline-offset-4">Sign in</Link>
                </>
            }
        >
            <div role="tablist" aria-label="Account type" className="mb-6 grid grid-cols-2 gap-1 rounded-md bg-muted p-1 text-sm">
                {(['trade', 'public'] as const).map((t) => (
                    <button
                        key={t}
                        type="button"
                        role="tab"
                        aria-selected={active === t}
                        onClick={() => choose(t)}
                        className={cn('min-h-10 rounded px-3 font-medium transition-colors', active === t ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground')}
                    >
                        {t === 'trade' ? 'Trade business' : 'Customer'}
                    </button>
                ))}
            </div>

            {active === 'trade' ? <TradeForm businessTypes={business_types} /> : <PublicForm />}
        </AuthLayout>
    );
}

function PublicForm() {
    const form = useForm({ first_name: '', last_name: '', email: '', password: '', password_confirmation: '', terms: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/register/public', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="First name" autoComplete="given-name" required value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} error={form.errors.first_name} />
                <Field label="Last name" autoComplete="family-name" required value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} error={form.errors.last_name} />
            </div>
            <Field label="Email" type="email" autoComplete="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={form.errors.email} />
            <PasswordFields form={form} />
            <Checkbox
                label="I accept the terms of sale."
                checked={form.data.terms}
                onChange={(v) => form.setData('terms', v)}
                error={form.errors.terms}
            />
            <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                Create account
            </Button>
        </form>
    );
}

function TradeForm({ businessTypes }: { businessTypes: RegisterProps['business_types'] }) {
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        company_name: '',
        business_type: '',
        registration_number: '',
        vat_number: '',
        estimated_monthly_spend: '',
        address: { line1: '', line2: '', city: '', county: '', postcode: '' },
        terms: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const setAddress = (key: keyof typeof form.data.address, value: string) => form.setData('address', { ...form.data.address, [key]: value });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, estimated_monthly_spend: data.estimated_monthly_spend === '' ? null : data.estimated_monthly_spend }));
        form.post('/register/trade', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit} className="space-y-8" noValidate>
            <Section title="About you">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="First name" autoComplete="given-name" required value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} error={errors.first_name} />
                    <Field label="Last name" autoComplete="family-name" required value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} error={errors.last_name} />
                    <Field label="Email" type="email" autoComplete="email" required value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} error={errors.email} />
                    <Field label="Phone" type="tel" autoComplete="tel" required value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} error={errors.phone} />
                </div>
                <PasswordFields form={form} />
            </Section>

            <Section title="Your business">
                <Field label="Company name" autoComplete="organization" required value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} error={errors.company_name} />
                <SelectField
                    label="Type of business"
                    value={form.data.business_type}
                    onChange={(v) => form.setData('business_type', v)}
                    options={businessTypes}
                    error={errors.business_type}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Companies House number"
                        value={form.data.registration_number}
                        onChange={(e) => form.setData('registration_number', e.target.value)}
                        error={errors.registration_number}
                        hint="8 digits, or 2 letters and 6 digits."
                    />
                    <Field label="VAT number" value={form.data.vat_number} onChange={(e) => form.setData('vat_number', e.target.value)} error={errors.vat_number} hint="e.g. GB123456789" />
                </div>
                <Field
                    label="Estimated monthly spend (£, excluding VAT)"
                    inputMode="numeric"
                    value={form.data.estimated_monthly_spend}
                    onChange={(e) => form.setData('estimated_monthly_spend', e.target.value.replace(/\D/g, ''))}
                    error={errors.estimated_monthly_spend}
                    hint="Helps us suggest the right terms. Not binding."
                />
                <p className="text-xs text-muted-foreground">No VAT or company number? That's fine — plenty of market traders and new businesses don't have one yet.</p>
            </Section>

            <Section title="Trading address">
                <Field label="Address line 1" autoComplete="address-line1" required value={form.data.address.line1} onChange={(e) => setAddress('line1', e.target.value)} error={errors['address.line1']} />
                <Field label="Address line 2" autoComplete="address-line2" value={form.data.address.line2} onChange={(e) => setAddress('line2', e.target.value)} error={errors['address.line2']} />
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Town or city" autoComplete="address-level2" required value={form.data.address.city} onChange={(e) => setAddress('city', e.target.value)} error={errors['address.city']} />
                    <Field label="County" autoComplete="address-level1" value={form.data.address.county} onChange={(e) => setAddress('county', e.target.value)} error={errors['address.county']} />
                    <Field label="Postcode" autoComplete="postal-code" required value={form.data.address.postcode} onChange={(e) => setAddress('postcode', e.target.value)} error={errors['address.postcode']} />
                </div>
            </Section>

            <Checkbox label="I accept the terms of trade." checked={form.data.terms} onChange={(v) => form.setData('terms', v)} error={errors.terms} />

            <Button type="submit" className="h-11 w-full" disabled={form.processing}>
                {form.processing ? 'Sending…' : 'Apply for a trade account'}
            </Button>
        </form>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <fieldset className="space-y-4">
            <legend className="mb-1 text-sm font-semibold">{title}</legend>
            {children}
        </fieldset>
    );
}

interface PasswordForm {
    data: { password: string; password_confirmation: string };
    errors: Partial<Record<string, string>>;
    setData: (key: 'password' | 'password_confirmation', value: string) => void;
}

function PasswordFields({ form }: { form: PasswordForm }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Field
                label="Password"
                type="password"
                autoComplete="new-password"
                required
                minLength={12}
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
                error={form.errors.password}
                hint={PASSWORD_HINT}
            />
            <Field
                label="Confirm password"
                type="password"
                autoComplete="new-password"
                required
                value={form.data.password_confirmation}
                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                error={form.errors.password_confirmation}
            />
        </div>
    );
}

function SelectField({ label, value, onChange, options, error }: { label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[]; error?: string }) {
    const id = useId();

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <select
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                required
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                className={cn('flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-sm md:h-10 md:text-sm', error && 'border-red-500')}
            >
                <option value="" disabled>
                    Choose…
                </option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            {error && (
                <p id={`${id}-error`} className="text-xs text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
