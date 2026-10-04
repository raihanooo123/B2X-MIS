<?php

namespace App\Domain\Ordering;

use App\Models\Order;

/**
 * 05.15 §6.2 — a guest's link to their order page: an HMAC over the
 * order's `public_id` and `guest_email`, valid for 90 days. A new one is
 * sent from *Find my order*.
 *
 * Built like EmailVerificationLink rather than with Laravel's temporary
 * signed routes, which in Laravel 11 carry an unpatched path-confusion
 * advisory (ROADMAP §0.8). The email is in the MAC, so a link only ever
 * opens the order it was issued for, to the address it was sent to.
 *
 * Not a sign-in link: it shows one order and nothing else.
 */
final class GuestOrderLink
{
    public const TTL_SECONDS = 90 * 86400;

    public static function url(Order $order, ?int $now = null): string
    {
        $expires = ($now ?? now()->getTimestamp()) + self::TTL_SECONDS;

        return route('orders.guest', [
            'order' => $order->public_id,
            'expires' => $expires,
            'signature' => self::signature($order, $expires),
        ]);
    }

    public static function isValid(Order $order, int $expires, string $signature, ?int $now = null): bool
    {
        return $order->guest_email !== null
            && $expires >= ($now ?? now()->getTimestamp())
            && hash_equals(self::signature($order, $expires), $signature);
    }

    /**
     * Where the customer's emails point: the guest link while the order
     * belongs to no account, the signed-in page once it does (claimed, or
     * never a guest order).
     */
    public static function customerUrl(Order $order): string
    {
        return $order->user_id === null && $order->company_id === null && $order->guest_email !== null
            ? self::url($order)
            : route('orders.confirmation', ['order' => $order->public_id]);
    }

    private static function signature(Order $order, int $expires): string
    {
        return hash_hmac('sha256', "guest-order|{$order->public_id}|".mb_strtolower((string) $order->guest_email)."|{$expires}", (string) config('app.key'));
    }
}
