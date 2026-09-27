/**
 * Registration (05.13 §5): a trade application that also creates the
 * login (§5.1, fields from 05.2 §5.1), or a public customer account
 * (§5.2). Either way the next step is the same "check your inbox"
 * confirmation — the form never reveals whether an address already has
 * an account.
 *
 * The trade form shows the terms of trade in force and submits their
 * version (02 §25.1). If they change while the form is open, the server
 * refuses the submission and sends the new version; the tick is cleared
 * so the applicant accepts what they have now been shown.
 */
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useId, useState, type FormEvent } from 'react';

import { AuthLayout } from '@/components/auth/AuthLayout';
import { Checkbox, Field } from '@/components/auth/Field';
import { clearPasswords } from '@/components/auth/passwordInputs';
import { PASSWORD_HINT } from '@/components/auth/passwordHint';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type AccountType = 'trade' | 'public';

interface TradeTerms {
    id: number;
    version: string;
    /** Rendered server-side from Markdown, raw HTML escaped. */
    html: string;
}

interface RegisterProps {
    type: AccountType;
    business_types: { value: string; label: string }[];
    legal_forms: { value: string; label: string }[];
    trade_terms: TradeTerms | null;
}

/** 02 §25.3: Companies House must know these forms. */
const NEEDS_COMPANY_NUMBER = ['limited_company', 'llp'];

export default function Register({ type, business_types, legal_forms, trade_terms }: RegisterProps) {
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

            {active === 'trade' ? <TradeForm businessTypes={business_types} legalForms={legal_forms} terms={trade_terms} /> : <PublicForm />}
        </AuthLayout>
    );
}

function PublicForm() {
    const form = useForm({ first_name: '', last_name: '', email: '', password: '', password_confirmation: '', terms: false });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const element = e.currentTarget;
        const values = new FormData(element);
        form.transform((data) => ({ ...data, password: String(values.get('password') ?? ''), password_confirmation: String(values.get('password_confirmation') ?? '') }));
        form.post('/register/public', { onFinish: () => { form.reset('password', 'password_confirmation'); clearPasswords(element); } });
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

function TradeForm({ businessTypes, legalForms, terms }: { businessTypes: RegisterProps['business_types']; legalForms: RegisterProps['legal_forms']; terms: TradeTerms | null }) {
    if (terms === null) {
        return (
            <p role="status" className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                Trade applications are not open at the moment. Please try again later, or create a customer account to buy at our standard prices.
            </p>
        );
    }

    return <TradeApplicationForm businessTypes={businessTypes} legalForms={legalForms} terms={terms} />;
}

function TradeApplicationForm({ businessTypes, legalForms, terms }: { businessTypes: RegisterProps['business_types']; legalForms: RegisterProps['legal_forms']; terms: TradeTerms }) {
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        company_name: '',
        legal_form: '',
        business_type: '',
        registration_number: '',
        vat_number: '',
        estimated_monthly_spend: '',
        address: { line1: '', line2: '', city: '', county: '', postcode: '' },
        terms: false,
        terms_version_id: terms.id,
    });

    // New terms arrived with a refusal: record the version now shown and
    // make the applicant tick again for it.
    useEffect(() => {
        form.setData((data) => (data.terms_version_id === terms.id ? data : { ...data, terms_version_id: terms.id, terms: false }));
    }, [terms.id]);

    const needsCompanyNumber = NEEDS_COMPANY_NUMBER.includes(form.data.legal_form);
    const errors = form.errors as Record<string, string | undefined>;
    const setAddress = (key: keyof typeof form.data.address, value: string) => form.setData('address', { ...form.data.address, [key]: value });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const element = e.currentTarget;
        const values = new FormData(element);
        form.transform((data) => ({ ...data, password: String(values.get('password') ?? ''), password_confirmation: String(values.get('password_confirmation') ?? ''), estimated_monthly_spend: data.estimated_monthly_spend === '' ? null : data.estimated_monthly_spend }));
        form.post('/register/trade', { onFinish: () => { form.reset('password', 'password_confirmation'); clearPasswords(element); } });
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
                <SelectField label="Legal form" value={form.data.legal_form} onChange={(v) => form.setData('legal_form', v)} options={legalForms} error={errors.legal_form} />
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
                        required={needsCompanyNumber}
                        value={form.data.registration_number}
                        onChange={(e) => form.setData('registration_number', e.target.value)}
                        error={errors.registration_number}
                        hint={needsCompanyNumber ? 'Required for a limited company or LLP. 8 digits, or 2 letters and 6 digits.' : '8 digits, or 2 letters and 6 digits.'}
                    />
                    <Field label="VAT number" value={form.data.vat_number} onChange={(e) => form.setData('vat_number', e.target.value)} error={errors.vat_number} hint="e.g. GB123456782 (XI123456782 in Northern Ireland)" />
                </div>
                <Field
                    label="Estimated monthly spend (£, excluding VAT)"
                    inputMode="numeric"
                    value={form.data.estimated_monthly_spend}
                    onChange={(e) => form.setData('estimated_monthly_spend', e.target.value.replace(/\D/g, ''))}
                    error={errors.estimated_monthly_spend}
                    hint="Helps us suggest the right terms. Not binding."
                />
                <p className="text-xs text-muted-foreground">
                    No VAT number? That&apos;s fine — plenty of market traders and new businesses aren&apos;t VAT-registered. Sole traders and ordinary partnerships have no Companies House number.
                </p>
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

            <Section title={`Terms of trade (version ${terms.version})`}>
                {errors.terms_version_id && (
                    <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        {errors.terms_version_id}
                    </p>
                )}
                <div
                    tabIndex={0}
                    aria-label="Terms of trade"
                    className="max-h-64 space-y-2 overflow-y-auto rounded-md border p-4 text-sm [&_h1]:text-base [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-medium [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
                    dangerouslySetInnerHTML={{ __html: terms.html }}
                />
                <Checkbox label={`I have read and accept the terms of trade (version ${terms.version}).`} checked={form.data.terms} onChange={(v) => form.setData('terms', v)} error={errors.terms} />
            </Section>

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
