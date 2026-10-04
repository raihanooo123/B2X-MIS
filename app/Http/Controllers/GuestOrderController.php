<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Registration;
use App\Domain\Notifications\Notices\EmailVerification;
use App\Domain\Notifications\Notices\ExistingAccount;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\GuestOrderLink;
use App\Domain\Ordering\OrderCancellationService;
use App\Http\Requests\Web\GuestAccountRequest;
use App\Http\Support\OrderPageProps;
use App\Http\Support\PriceDisplay;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 05.15 §6.2–6.3 — a guest's order page, opened by the signed link in their
 * emails (GuestOrderLink), and "Save your details — set a password".
 *
 * The link shows this one order and is not a sign-in. An invalid or
 * expired link goes to *Find my order* for a new one.
 *
 * Saving details answers the same way whether or not the email already has
 * an account (05.13 no-enumeration): an existing account is emailed how to
 * sign in; otherwise a public customer is created, as registration does,
 * and asked to verify the address. Verifying it claims the guest's orders
 * (GuestOrderClaims).
 */
class GuestOrderController extends Controller
{
    private const INVALID = 'That order link is invalid or has expired. Enter your order number and email, and we will send you a new one.';

    private const SAVED = 'Thank you. Check your inbox — we have sent you an email. Once your address is confirmed, your orders will be in your account.';

    public function show(Request $request, string $order, int $expires, string $signature): Response|RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }

        return Inertia::render('Orders/Confirmation', [
            'display_mode' => PriceDisplay::checkoutMode($request),
            'order' => OrderPageProps::for($model),
            'guest' => [
                'email' => $model->guest_email,
                // Offered until the order belongs to an account. Never says
                // whether this email already has one.
                'can_save_details' => $model->user_id === null && ! $request->user() instanceof User,
                'account_url' => route('orders.guest.account', ['order' => $order, 'expires' => $expires, 'signature' => $signature]),
                'status' => $request->session()->get('status'),
            ],
            'cancel_url' => OrderPageProps::canCancel($model)
                ? route('orders.guest.cancel', ['order' => $order, 'expires' => $expires, 'signature' => $signature])
                : null,
        ]);
    }

    /** 05.4 §13.2: a guest cancels before dispatch, by their order link. */
    public function cancel(string $order, int $expires, string $signature): RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }

        try {
            (new OrderCancellationService)->cancel($model->id);
        } catch (OrderNotCancellableException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', 'Your order is cancelled. We have emailed you a confirmation.');
    }

    public function createAccount(GuestAccountRequest $request, string $order, int $expires, string $signature): RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null || $model->guest_email === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }

        $email = $model->guest_email;
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            (new Notifications)->toUser(new ExistingAccount($existing->id), $existing);
        } else {
            $user = Registration::publicCustomer($request->person($email));
            (new Notifications)->toUser(new EmailVerification($user->id), $user);
        }

        return back()->with('status', self::SAVED);
    }

    private function linkedOrder(string $publicId, int $expires, string $signature): ?Order
    {
        $model = Order::query()->where('public_id', $publicId)->first();

        return $model !== null && GuestOrderLink::isValid($model, $expires, $signature) ? $model : null;
    }
}
