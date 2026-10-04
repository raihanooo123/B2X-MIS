<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Notices\OrderAccessLink;
use App\Domain\Notifications\Notifications;
use App\Domain\Notifications\Recipient;
use App\Http\Requests\Web\OrderLookupRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.15 §6.2 *Find my order* (`/orders/lookup`): order number and email →
 * a fresh link to the order page, emailed to the order's guest email. The
 * reply is the same whether or not they match, and requests are limited
 * like password reset: 5 a minute per IP and per email (06 §12).
 *
 * Only a guest order has a link to send. An order placed signed in is
 * found by signing in, and the same reply is given.
 */
class OrderLookupController extends Controller
{
    private const PER_MINUTE = 5;

    private const GENERIC = 'If those details match an order, we have emailed a link to it. Check your inbox.';

    public function show(Request $request): Response
    {
        return Inertia::render('Orders/Lookup', ['status' => $request->session()->get('status')]);
    }

    public function send(OrderLookupRequest $request): RedirectResponse
    {
        $email = $request->email();
        $keys = ['orders:lookup:ip:'.$request->ip(), 'orders:lookup:id:'.hash('sha256', $email)];
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
                return back()->withErrors(['email' => 'Too many requests. Please wait a minute and try again.']);
            }
        }
        foreach ($keys as $key) {
            RateLimiter::hit($key, 60);
        }

        // orders_number_uq finds the order; the email is compared after (02 §26.1).
        $order = Order::query()->where('order_number', $request->orderNumber())->first(['id', 'guest_email']);
        if ($order !== null && $order->guest_email !== null && hash_equals($order->guest_email, $email)) {
            (new Notifications)->toRecipient(new OrderAccessLink($order->id), new Recipient($order->guest_email));
        }

        return back()->with('status', self::GENERIC);
    }
}
