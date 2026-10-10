/**
 * 05.2 §8.1 row 3, §18.1 — paying for a trade order that went for approval.
 *
 *   - approved, prepaid by card: authorise the order total with Stripe
 *     Elements (07 §6.4: card details go to Stripe only), then the server
 *     records it, confirms the order and captures after commit. Two hours
 *     from approval, else the order is cancelled.
 *   - over available credit and waiting for accounts: the buyer may pay by
 *     card instead — stock is reserved now; any company approval still
 *     applies first.
 */
import { router } from '@inertiajs/react';
import { CardElement, Elements, useElements, useStripe } from '@stripe/react-stripe-js';
import { Clock, CreditCard, Loader2, Lock } from 'lucide-react';
import { useMemo, useRef, useState, type FormEvent } from 'react';

import { ConfirmDialog } from '@/components/trade/ConfirmDialog';
import { Money } from '@/components/trade/Money';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState } from '@/components/trade/states';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { Button } from '@/components/ui/button';
import type { ApiError } from '@/lib/api/client';
import { approvedOrderCardIntent, payApprovedOrder, payInAdvance } from '@/lib/api/credit';
import { formatUkDateTime } from '@/lib/dateTime';
import { declineMessage, GENERAL_DECLINE } from '@/lib/payments/declines';
import { getStripe } from '@/lib/payments/stripe';
import { toast } from '@/stores/toastStore';

interface Props {
    order: { id: string; order_number: string; status: string; total_gross_minor: number; pay_by: string | null; confirmation_url: string };
    refusal: { code: string; message: string } | null;
    can_pay_in_advance: boolean;
    stripe_key: string | null;
}

function CardPayment({ order }: { order: Props['order'] }) {
    const stripe = useStripe();
    const elements = useElements();
    const tz = useDisplayTimezone();
    const [complete, setComplete] = useState(false);
    const [stage, setStage] = useState<'idle' | 'authorising' | 'confirming'>('idle');
    const [error, setError] = useState<string | null>(null);
    const idempotency = useRef<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        const card = elements?.getElement(CardElement);
        if (!stripe || !card) {
            return;
        }
        setError(null);
        setStage('authorising');
        try {
            const intent = (await approvedOrderCardIntent(order.id)).data;
            if (intent.status !== 'requires_capture') {
                if (intent.client_secret === null) {
                    throw new Error(GENERAL_DECLINE);
                }
                const result = await stripe.confirmCardPayment(intent.client_secret, { payment_method: { card } });
                if (result.error || result.paymentIntent?.status !== 'requires_capture') {
                    setError(result.error ? declineMessage(result.error) : GENERAL_DECLINE);
                    setStage('idle');

                    return;
                }
            }
            setStage('confirming');
            idempotency.current ??= crypto.randomUUID();
            const paid = await payApprovedOrder(order.id, intent.id, idempotency.current);
            toast.success(`Order ${order.order_number} confirmed`, 'Your payment was taken.');
            router.visit(paid.data.confirmation_url);
        } catch (e) {
            setError((e as ApiError).message);
            setStage('idle');
            idempotency.current = null;
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 rounded-xl border bg-background p-5">
            <h2 className="flex items-center gap-2 text-base font-semibold">
                <CreditCard className="size-5" aria-hidden /> Pay by card
            </h2>
            {order.pay_by && (
                <p className="flex items-start gap-2 text-sm">
                    <Clock className="mt-0.5 size-4 shrink-0" aria-hidden /> Pay by {formatUkDateTime(order.pay_by, tz, true)}, or the order is cancelled and its stock released.
                </p>
            )}
            <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium" id="card-label">Card details</span>
                <div className="rounded-md border px-3 py-3" aria-labelledby="card-label">
                    <CardElement options={{ hidePostalCode: false, style: { base: { fontSize: '16px' } } }} onChange={(ev) => { setComplete(ev.complete); setError(null); }} />
                </div>
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground"><Lock className="size-3" aria-hidden /> Card details go securely to our payment provider, Stripe. We never see or store your card number.</p>
            </div>
            {error && <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{error}</p>}
            <Button type="submit" className="h-11" disabled={!complete || stage !== 'idle'} aria-busy={stage !== 'idle'}>
                {stage !== 'idle' && <Loader2 className="animate-spin" aria-hidden />}
                {stage === 'authorising' ? 'Checking your card…' : stage === 'confirming' ? 'Confirming your order…' : <>Pay <Money minor={order.total_gross_minor} /></>}
            </Button>
        </form>
    );
}

export default function Pay({ order, refusal, can_pay_in_advance, stripe_key }: Props) {
    const stripePromise = useMemo(() => getStripe(stripe_key), [stripe_key]);
    const [confirming, setConfirming] = useState(false);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const idempotency = useRef<string | null>(null);

    const switchToCard = async () => {
        idempotency.current ??= crypto.randomUUID();
        setPending(true);
        setError(null);
        try {
            const result = await payInAdvance(order.id, idempotency.current);
            setConfirming(false);
            toast.success('Stock reserved', result.data.status === 'pending_payment' ? 'Now pay by card to confirm the order.' : 'It still needs your company’s approval before you pay.');
            router.reload();
        } catch (e) {
            setError((e as ApiError).message);
        } finally {
            setPending(false);
        }
    };

    return (
        <TradeShell title={`Pay for ${order.order_number}`}>
            <PageHeader
                breadcrumbs={[{ label: 'Orders', href: order.confirmation_url }, { label: `Pay for ${order.order_number}` }]}
                title={`Pay for order ${order.order_number}`}
                description={<p>Total including VAT and carriage: <Money minor={order.total_gross_minor} className="font-semibold text-foreground" /></p>}
                secondaryActions={[{ label: 'View the order', href: order.confirmation_url }]}
            />

            <div className="max-w-xl">
                {can_pay_in_advance ? (
                    <EmptyState
                        title="Waiting for accounts to approve the credit"
                        action={<Button className="h-11" onClick={() => setConfirming(true)}>Pay by card instead…</Button>}
                    >
                        This order is more than your available credit, so its stock is not reserved yet. Pay by card now instead of waiting: the stock is reserved straight away.
                    </EmptyState>
                ) : refusal ? (
                    <EmptyState title="This order cannot be paid here" action={<Button asChild variant="outline" className="h-11"><a href={order.confirmation_url}>View the order</a></Button>}>
                        {refusal.message}
                    </EmptyState>
                ) : stripePromise === null ? (
                    <EmptyState title="Card payments are not available right now">Please contact us to pay for this order.</EmptyState>
                ) : (
                    <Elements stripe={stripePromise}>
                        <CardPayment order={order} />
                    </Elements>
                )}
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={(o) => { setConfirming(o); setError(null); }}
                title="Pay by card instead?"
                facts={[{ label: 'Order', value: order.order_number }, { label: 'Total inc VAT', value: <Money minor={order.total_gross_minor} /> }]}
                consequences="Your stock is reserved now and the order no longer waits for accounts. You pay by card once any company approval is given; nothing is charged yet."
                confirmLabel="Reserve stock and pay by card"
                pending={pending}
                error={error}
                onConfirm={switchToCard}
            />
        </TradeShell>
    );
}
