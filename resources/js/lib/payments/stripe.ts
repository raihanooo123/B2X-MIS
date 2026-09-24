/**
 * Stripe.js, loaded once per publishable key (07 §6.4). Card details are
 * typed into Stripe Elements — an iframe served by Stripe — and go
 * straight to Stripe. This app never sees a card number, expiry or CVC,
 * which keeps it in PCI DSS SAQ-A scope.
 */
import { loadStripe, type Stripe } from '@stripe/stripe-js';

const loaded = new Map<string, Promise<Stripe | null>>();

export function getStripe(publishableKey: string | null): Promise<Stripe | null> | null {
    if (!publishableKey) {
        return null;
    }

    let promise = loaded.get(publishableKey);
    if (promise === undefined) {
        promise = loadStripe(publishableKey);
        loaded.set(publishableKey, promise);
    }

    return promise;
}
