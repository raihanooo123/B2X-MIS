/**
 * Checkout (06 §9.2–9.3): delivery address, delivery country, payment
 * method and the order summary, all from `/checkout/preview` — re-run
 * whenever the country (and so the VAT) changes.
 *
 * "Place order" stays disabled while preview reports any blocker; each is
 * shown with the server's own message (06 §9.2: "the client renders it
 * directly").
 *
 * The order is placed with the total the buyer is looking at
 * (`expected_total_gross_minor`). If the server's re-resolution differs
 * it answers 409 `price_changed`, commits nothing, and this page shows
 * what moved — the old and new total and every line that changed — then
 * re-previews and asks again. A silently repriced order is never
 * acceptable (06 §9.3).
 *
 * Each distinct request body gets its own `Idempotency-Key`; retrying the
 * same body reuses it, so a lost response replays instead of ordering
 * twice (06 §6).
 */
import { Head, Link, router } from '@inertiajs/react';
import { CardElement, Elements, useElements, useStripe } from '@stripe/react-stripe-js';
import { AlertTriangle, ArrowLeft, Loader2, Lock } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';

import { AccountMenu } from '@/components/auth/AccountMenu';
import { Field } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { ApiError } from '@/lib/api/client';
import { createCardIntent, useCheckoutPreview, usePlaceOrder, type CheckoutPreview, type DeliveryAddressInput, type PaymentMethod, type PreviewBlocker } from '@/lib/api/checkout';
import { useCart, type CartLine } from '@/lib/api/orderPad';
import { lineTotalMinor, totalsRows, vatLabel, type DisplayMode } from '@/lib/cart/display';
import { formatMinor, subtractInts } from '@/lib/money';
import { declineMessage, GENERAL_DECLINE } from '@/lib/payments/declines';
import { getStripe } from '@/lib/payments/stripe';
import { cn } from '@/lib/utils';

interface SavedAddress {
    key: string;
    label: string | null;
    contact_name: string | null;
    phone: string | null;
    line1: string;
    line2: string | null;
    city: string;
    county: string | null;
    postcode: string;
    country_code: string;
    is_default: boolean;
}

interface CheckoutProps {
    display_mode: DisplayMode;
    is_trade: boolean;
    company_name: string | null;
    contact: { name: string; phone: string | null };
    addresses: SavedAddress[];
    countries: { code: string; name: string }[];
    payment_methods: { value: PaymentMethod; label: string }[];
    /** Stripe publishable key; null when card payments are not configured. */
    stripe_key: string | null;
}

const NEW_ADDRESS = 'new';

const PAYMENT_HELP: Record<PaymentMethod, string> = {
    on_account: 'Invoiced on your account terms.',
    card: 'Pay now by debit or credit card. Your card is charged when your order is placed.',
    bacs: 'Pay by bank transfer, quoting your order number. We dispatch once payment has cleared.',
};

/** Blocker codes with a place to go and fix them. */
const BLOCKER_LINKS: Record<string, { href: string; label: string }> = {
    email_unverified: { href: '/email/verify', label: 'Resend the link' },
    sign_in_required: { href: '/login', label: 'Sign in' },
    cart_empty: { href: '/order-pad', label: 'Go to the order pad' },
};

interface PriceChange {
    expectedMinor: number;
    actualMinor: number;
    before: CheckoutPreview;
}

/**
 * Stripe Elements wraps the whole form so the card field and the submit
 * handler share one Stripe instance. With no publishable key the card
 * option is not offered at all (CheckoutPageController).
 */
export default function CheckoutIndex(props: CheckoutProps) {
    const stripePromise = useMemo(() => getStripe(props.stripe_key), [props.stripe_key]);

    return (
        <Elements stripe={stripePromise}>
            <CheckoutForm {...props} />
        </Elements>
    );
}

function CheckoutForm(props: CheckoutProps) {
    const { display_mode: mode, is_trade: isTrade, addresses, countries, payment_methods: methods } = props;
    const defaultCountry = countries.find((c) => c.code === 'GB')?.code ?? countries[0]?.code ?? 'GB';

    const blankAddress: DeliveryAddressInput = {
        contact_name: props.contact.name,
        company_name: props.company_name ?? '',
        phone: props.contact.phone ?? '',
        line1: '',
        line2: '',
        city: '',
        county: '',
        postcode: '',
        country_code: defaultCountry,
    };

    const [addressChoice, setAddressChoice] = useState<string>(addresses[0]?.key ?? NEW_ADDRESS);
    const [newAddress, setNewAddress] = useState<DeliveryAddressInput>(blankAddress);
    const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>(methods[0]?.value ?? 'card');
    const [reference, setReference] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<ApiError | null>(null);
    const [priceChange, setPriceChange] = useState<PriceChange | null>(null);

    const address: DeliveryAddressInput = useMemo(() => {
        const saved = addresses.find((a) => a.key === addressChoice);
        if (saved === undefined) {
            return newAddress;
        }

        return {
            contact_name: saved.contact_name || props.contact.name,
            company_name: props.company_name ?? '',
            phone: saved.phone ?? props.contact.phone ?? '',
            line1: saved.line1,
            line2: saved.line2 ?? '',
            city: saved.city,
            county: saved.county ?? '',
            postcode: saved.postcode,
            country_code: saved.country_code,
        };
    }, [addressChoice, addresses, newAddress, props.company_name, props.contact.name, props.contact.phone]);

    const cart = useCart();
    const preview = useCheckoutPreview(address.country_code);
    const placeOrder = usePlaceOrder();
    const idempotency = useRef<{ body: string; key: string } | null>(null);

    const blockers = preview.data?.blockers ?? [];
    const addressComplete = [address.contact_name, address.line1, address.city, address.postcode, address.country_code].every((v) => v.trim() !== '');
    const stripe = useStripe();
    const elements = useElements();
    const [stage, setStage] = useState<'idle' | 'authorising' | 'placing'>('idle');
    const [cardComplete, setCardComplete] = useState(false);
    const [cardError, setCardError] = useState<string | null>(null);
    const payingByCard = paymentMethod === 'card';
    const cardReady = !payingByCard || (stripe !== null && elements !== null && cardComplete);

    const canPlace = preview.data !== undefined && !preview.isFetching && blockers.length === 0 && addressComplete && cardReady && stage === 'idle';

    const showFailure = (error: ApiError, shown: CheckoutPreview) => {
        if (error.code === 'price_changed') {
            const meta = error.details[0]?.meta ?? {};
            setPriceChange({
                expectedMinor: Number(meta.expected_total_gross_minor ?? shown.total_gross_minor),
                actualMinor: Number(meta.actual_total_gross_minor ?? shown.total_gross_minor),
                before: shown,
            });
        } else if (error.code === 'validation_failed') {
            setFieldErrors(Object.fromEntries(error.details.filter((d) => d.field).map((d) => [String(d.field), d.message])));
        } else if (error.code === 'card_declined') {
            setCardError(error.message);
        } else {
            setFailure(error);
        }
        // Whatever went wrong, show the server's current view.
        void preview.refetch();
    };

    /**
     * Card (07 §6.4): get this total's authorisation (reused on retry, so it
     * is never authorised twice), then let Stripe confirm the card against
     * it — including any 3-D Secure check. A decline stops here: no order,
     * nothing reserved, nothing charged. Returns the authorised intent's id.
     */
    const authoriseCard = async (expectedMinor: number): Promise<string | null> => {
        const intent = await createCardIntent({ expected_total_gross_minor: expectedMinor, delivery_country_code: address.country_code });
        if (intent.status === 'requires_capture') {
            return intent.id;
        }

        const card = elements?.getElement(CardElement);
        if (!stripe || !card || intent.client_secret === null) {
            setCardError('Card payments are not available right now. Please choose another payment method.');

            return null;
        }

        const result = await stripe.confirmCardPayment(intent.client_secret, {
            payment_method: {
                card,
                billing_details: { name: address.contact_name, address: { line1: address.line1, city: address.city, postal_code: address.postcode, country: address.country_code } },
            },
        });

        if (result.error) {
            setCardError(declineMessage(result.error));

            return null;
        }

        if (result.paymentIntent?.status !== 'requires_capture') {
            setCardError(GENERAL_DECLINE);

            return null;
        }

        return intent.id;
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!canPlace || preview.data === undefined) {
            return;
        }

        const shown = preview.data;
        setFailure(null);
        setFieldErrors({});
        setCardError(null);

        let paymentIntentId: string | undefined;
        if (payingByCard) {
            setStage('authorising');
            try {
                paymentIntentId = (await authoriseCard(shown.total_gross_minor)) ?? undefined;
            } catch (error) {
                setStage('idle');
                showFailure(error as ApiError, shown);

                return;
            }
            if (paymentIntentId === undefined) {
                setStage('idle');

                return;
            }
        }

        const input = {
            payment_method: paymentMethod,
            expected_total_gross_minor: shown.total_gross_minor,
            customer_reference: isTrade ? reference : '',
            delivery_address: address,
            ...(paymentIntentId ? { payment_intent_id: paymentIntentId } : {}),
        };
        const body = JSON.stringify(input);
        if (idempotency.current?.body !== body) {
            idempotency.current = { body, key: crypto.randomUUID() };
        }

        setStage('placing');
        try {
            const order = await placeOrder.mutateAsync({ input, idempotencyKey: idempotency.current.key });
            setPriceChange(null);
            router.visit(order.confirmation_url);
        } catch (error) {
            setStage('idle');
            showFailure(error as ApiError, shown);
        }
    };

    return (
        <>
            <Head title="Checkout" />
            <div className="mx-auto max-w-[1100px] px-4 py-4">
                <header className="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="flex items-center gap-3">
                        <Link href="/cart" className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
                            <ArrowLeft className="size-4" aria-hidden /> Cart
                        </Link>
                        <h1 className="text-lg font-semibold tracking-tight">Checkout</h1>
                    </div>
                    <AccountMenu />
                </header>

                <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-[1fr_380px] lg:items-start">
                    <div className="space-y-8">
                        <Section title="Delivery address">
                            {addresses.length > 0 && (
                                <fieldset className="space-y-2">
                                    <legend className="sr-only">Choose a delivery address</legend>
                                    {addresses.map((a) => (
                                        <Choice key={a.key} name="address" value={a.key} checked={addressChoice === a.key} onChange={setAddressChoice}>
                                            <span className="font-medium">{a.label || a.line1}</span>
                                            {a.is_default && <span className="ml-2 text-xs text-muted-foreground">Default</span>}
                                            <span className="block text-xs text-muted-foreground">
                                                {[a.line1, a.line2, a.city, a.postcode, countries.find((c) => c.code === a.country_code)?.name ?? a.country_code].filter(Boolean).join(', ')}
                                            </span>
                                        </Choice>
                                    ))}
                                    <Choice name="address" value={NEW_ADDRESS} checked={addressChoice === NEW_ADDRESS} onChange={setAddressChoice}>
                                        <span className="font-medium">A different address</span>
                                    </Choice>
                                </fieldset>
                            )}

                            {addressChoice === NEW_ADDRESS && (
                                <AddressForm value={newAddress} onChange={setNewAddress} countries={countries} errors={fieldErrors} isTrade={isTrade} />
                            )}
                            {addressChoice !== NEW_ADDRESS && (
                                <p className="text-xs text-muted-foreground">
                                    Delivering to {countries.find((c) => c.code === address.country_code)?.name ?? address.country_code}. VAT is charged at that country's rates.
                                </p>
                            )}
                        </Section>

                        <Section title="Payment">
                            <fieldset className="space-y-2">
                                <legend className="sr-only">Payment method</legend>
                                {methods.map((m) => (
                                    <Choice key={m.value} name="payment_method" value={m.value} checked={paymentMethod === m.value} onChange={(v) => setPaymentMethod(v as PaymentMethod)}>
                                        <span className="font-medium">{m.label}</span>
                                        <span className="block text-xs text-muted-foreground">{PAYMENT_HELP[m.value]}</span>
                                    </Choice>
                                ))}
                            </fieldset>
                            {fieldErrors.payment_method && <p className="text-xs text-red-700">{fieldErrors.payment_method}</p>}
                            {payingByCard && (
                                <CardField
                                    error={cardError}
                                    disabled={stage !== 'idle'}
                                    onChange={(complete) => {
                                        setCardComplete(complete);
                                        setCardError(null);
                                    }}
                                />
                            )}
                            {paymentMethod === 'on_account' && preview.data?.credit && (
                                <p className={cn('text-xs', preview.data.credit.sufficient ? 'text-muted-foreground' : 'text-red-700')}>
                                    Available credit: {formatMinor(preview.data.credit.available_minor)}
                                    {!preview.data.credit.sufficient && ' — not enough for this order. Pay by card or bank transfer instead.'}
                                </p>
                            )}
                            {isTrade && (
                                <Field
                                    label="Your order reference"
                                    value={reference}
                                    maxLength={64}
                                    onChange={(e) => setReference(e.target.value)}
                                    error={fieldErrors.customer_reference}
                                    hint="Your purchase order number, if you use one. It appears on the invoice."
                                />
                            )}
                        </Section>
                    </div>

                    <aside className="space-y-4 rounded-md border p-4 lg:sticky lg:top-4" aria-label="Order summary">
                        <h2 className="text-sm font-semibold">Order summary</h2>

                        {priceChange && preview.data && <PriceChangeNotice change={priceChange} now={preview.data} cartLines={cart.data?.lines ?? []} mode={mode} />}

                        <OrderSummary preview={preview.data} loading={preview.isPending} error={preview.isError} cartLines={cart.data?.lines ?? []} mode={mode} />

                        {blockers.length > 0 && <Blockers blockers={blockers} />}

                        {failure && (
                            <div role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">
                                <p className="font-medium">{failure.message}</p>
                                {failure.details.length > 0 && (
                                    <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs">
                                        {failure.details.map((d, i) => (
                                            <li key={i}>{d.message}</li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        <Button type="submit" className="h-12 w-full text-base" disabled={!canPlace}>
                            {stage === 'authorising' ? (
                                <>
                                    <Loader2 className="animate-spin" /> Checking your card…
                                </>
                            ) : stage === 'placing' ? (
                                <>
                                    <Loader2 className="animate-spin" /> Placing order…
                                </>
                            ) : priceChange ? (
                                'Place order at the new total'
                            ) : (
                                'Place order'
                            )}
                        </Button>
                        {!addressComplete && <p className="text-center text-xs text-muted-foreground">Complete the delivery address to place your order.</p>}
                        {addressComplete && payingByCard && !cardComplete && <p className="text-center text-xs text-muted-foreground">Enter your card details to place your order.</p>}
                        <p className="text-center text-xs text-muted-foreground">Prices {vatLabel(mode)}. Delivery charges, if any, are confirmed with your order.</p>
                    </aside>
                </form>
            </div>
        </>
    );
}

/**
 * The card number, expiry and CVC are Stripe's own iframe (Elements):
 * typed there, sent to Stripe, never to this server (07 §6.4). The
 * postcode comes from the delivery address rather than a second field.
 */
function CardField({ error, disabled, onChange }: { error: string | null; disabled: boolean; onChange: (complete: boolean) => void }) {
    const id = useId();

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                Card details
            </label>
            <div id={id} className={cn('rounded-md border border-input px-3 py-3 shadow-sm', error && 'border-red-500', disabled && 'opacity-60')}>
                <CardElement
                    options={{
                        disabled,
                        // The delivery postcode is already collected and sent to
                        // Stripe as the billing postcode (confirmCardPayment's
                        // billing_details), so Elements does not ask again.
                        hidePostalCode: true,
                        style: { base: { fontSize: '16px', fontFamily: 'Inter, system-ui, sans-serif', color: '#0a0a0a', '::placeholder': { color: '#737373' } }, invalid: { color: '#b91c1c' } },
                    }}
                    onChange={(e) => onChange(e.complete)}
                />
            </div>
            {error ? (
                <p role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            ) : (
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    <Lock className="size-3" aria-hidden /> Card details go securely to our payment provider, Stripe. We never see or store your card number.
                </p>
            )}
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <h2 className="text-base font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Choice({ name, value, checked, onChange, children }: { name: string; value: string; checked: boolean; onChange: (value: string) => void; children: ReactNode }) {
    const id = useId();

    return (
        <label htmlFor={id} className={cn('flex min-h-11 cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 text-sm transition-colors hover:bg-accent', checked && 'border-primary bg-accent')}>
            <input id={id} type="radio" name={name} value={value} checked={checked} onChange={() => onChange(value)} className="mt-0.5 size-4 shrink-0 accent-primary" />
            <span className="min-w-0 flex-1">{children}</span>
        </label>
    );
}

function AddressForm({ value, onChange, countries, errors, isTrade }: { value: DeliveryAddressInput; onChange: (v: DeliveryAddressInput) => void; countries: { code: string; name: string }[]; errors: Record<string, string>; isTrade: boolean }) {
    const set = (key: keyof DeliveryAddressInput) => (e: { target: { value: string } }) => onChange({ ...value, [key]: e.target.value });
    const countryId = useId();

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Contact name" autoComplete="name" required value={value.contact_name} onChange={set('contact_name')} error={errors['delivery_address.contact_name']} />
                <Field label="Phone" type="tel" autoComplete="tel" value={value.phone} onChange={set('phone')} error={errors['delivery_address.phone']} />
            </div>
            {isTrade && <Field label="Company" autoComplete="organization" value={value.company_name} onChange={set('company_name')} error={errors['delivery_address.company_name']} />}
            <Field label="Address line 1" autoComplete="address-line1" required value={value.line1} onChange={set('line1')} error={errors['delivery_address.line1']} />
            <Field label="Address line 2" autoComplete="address-line2" value={value.line2} onChange={set('line2')} error={errors['delivery_address.line2']} />
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="Town or city" autoComplete="address-level2" required value={value.city} onChange={set('city')} error={errors['delivery_address.city']} />
                <Field label="County" autoComplete="address-level1" value={value.county} onChange={set('county')} error={errors['delivery_address.county']} />
                <Field label="Postcode" autoComplete="postal-code" required value={value.postcode} onChange={set('postcode')} error={errors['delivery_address.postcode']} />
            </div>
            <div className="space-y-1.5">
                <label htmlFor={countryId} className="text-sm font-medium">
                    Country
                </label>
                <select
                    id={countryId}
                    value={value.country_code}
                    onChange={set('country_code')}
                    autoComplete="country"
                    className="flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-sm md:h-10 md:text-sm"
                >
                    {countries.map((c) => (
                        <option key={c.code} value={c.code}>
                            {c.name}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-muted-foreground">VAT is charged at the delivery country's rates, so totals update when you change it.</p>
            </div>
        </div>
    );
}

function OrderSummary({ preview, loading, error, cartLines, mode }: { preview: CheckoutPreview | undefined; loading: boolean; error: boolean; cartLines: CartLine[]; mode: DisplayMode }) {
    if (preview === undefined) {
        return error ? (
            <p className="text-sm text-red-700">Totals couldn't be calculated. Try again in a moment.</p>
        ) : loading ? (
            <div className="space-y-2">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-2/3" />
            </div>
        ) : null;
    }

    const byId = new Map(cartLines.map((l) => [l.id, l]));

    return (
        <div className="space-y-3">
            <ol className="max-h-64 space-y-1.5 overflow-y-auto text-xs">
                {preview.lines.map((line, i) => {
                    const cartLine = byId.get(line.cart_line_id);
                    const total = lineTotalMinor(line, mode);

                    return (
                        <li key={line.cart_line_id} className="flex justify-between gap-3">
                            <span className="min-w-0">
                                <span className="mr-1.5 tabular-nums text-muted-foreground">{i + 1}.</span>
                                {cartLine ? `${cartLine.pack_qty.toLocaleString('en-GB')} × ${cartLine.sku.name ?? cartLine.sku.sku_code}` : 'Item'}
                                {cartLine && <span className="text-muted-foreground"> ({cartLine.pack.label})</span>}
                            </span>
                            <span className="shrink-0 tabular-nums">{total === null ? '—' : formatMinor(total)}</span>
                        </li>
                    );
                })}
            </ol>
            <dl className="space-y-1.5 border-t pt-3 text-sm tabular-nums" aria-live="polite">
                {totalsRows({ ...preview, spend_break_discount_minor: preview.spend_break?.discount_minor ?? 0 }, mode).map((row) => (
                    <div key={row.label} className="flex justify-between gap-4">
                        <dt className={cn(row.tone === 'strong' ? 'font-semibold' : 'text-muted-foreground')}>{row.label}</dt>
                        <dd className={cn(row.tone === 'strong' && 'text-base font-semibold', row.tone === 'discount' && 'text-emerald-700', row.tone === 'muted' && 'text-muted-foreground')}>{row.value}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function Blockers({ blockers }: { blockers: PreviewBlocker[] }) {
    return (
        <div role="alert" className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <p className="flex items-center gap-1.5 font-medium">
                <AlertTriangle className="size-4 shrink-0" aria-hidden /> {blockers.length === 1 ? 'One thing to sort out' : `${blockers.length} things to sort out`} before you can place this order
            </p>
            <ul className="mt-1.5 space-y-1 text-xs">
                {blockers.map((b, i) => {
                    const link = BLOCKER_LINKS[b.code];
                    const inCart = b.field?.startsWith('lines.') ?? false;

                    return (
                        <li key={`${b.code}-${i}`}>
                            {b.message}{' '}
                            {link ? (
                                <Link href={link.href} className="font-medium underline">
                                    {link.label}
                                </Link>
                            ) : (
                                inCart && (
                                    <Link href="/cart" className="font-medium underline">
                                        Change in cart
                                    </Link>
                                )
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * 06 §9.3's 409: the total the buyer saw, the total now, and each line
 * whose price moved — compared by cart line, in the page's display mode.
 */
function PriceChangeNotice({ change, now, cartLines, mode }: { change: PriceChange; now: CheckoutPreview; cartLines: CartLine[]; mode: DisplayMode }) {
    const names = new Map(cartLines.map((l) => [l.id, l.sku.name ?? l.sku.sku_code]));
    const before = new Map(change.before.lines.map((l) => [l.cart_line_id, lineTotalMinor(l, mode)]));
    const changed = now.lines.flatMap((l) => {
        const was = before.get(l.cart_line_id);
        const is = lineTotalMinor(l, mode);

        return was !== undefined && was !== null && is !== null && was !== is ? [{ id: l.cart_line_id, name: names.get(l.cart_line_id) ?? 'An item', was, is }] : [];
    });
    const difference = subtractInts(change.actualMinor, change.expectedMinor);

    useEffect(() => {
        document.getElementById('price-change-notice')?.focus();
    }, [change]);

    return (
        <div id="price-change-notice" tabIndex={-1} role="alert" className="rounded-md border border-amber-400 bg-amber-50 px-3 py-2 text-sm text-amber-950 outline-none">
            <p className="font-medium">Prices changed since you reviewed this order. Nothing has been placed.</p>
            <p className="mt-1 tabular-nums">
                Total was {formatMinor(change.expectedMinor)}, now {formatMinor(change.actualMinor)} ({difference > 0 ? '+' : '−'}
                {formatMinor(Math.abs(difference))}).
            </p>
            {changed.length > 0 && (
                <ul className="mt-1 space-y-0.5 text-xs tabular-nums">
                    {changed.map((c) => (
                        <li key={c.id}>
                            {c.name}: {formatMinor(c.was)} → {formatMinor(c.is)}
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-1 text-xs">Check the summary below, then place the order again to confirm the new total.</p>
        </div>
    );
}
