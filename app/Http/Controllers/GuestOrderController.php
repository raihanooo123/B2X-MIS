<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Registration;
use App\Domain\Notifications\Notices\EmailVerification;
use App\Domain\Notifications\Notices\ExistingAccount;
use App\Domain\Notifications\Notifications;
use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\GuestOrderLink;
use App\Domain\Ordering\OrderCancellationService;
use App\Domain\Ordering\PartialCancellations;
use App\Domain\Returns\ConsumerCancellations;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\FaultReports;
use App\Domain\Returns\ProofOfSending;
use App\Http\Requests\Web\CancellationItemsRequest;
use App\Http\Requests\Web\GuestAccountRequest;
use App\Http\Requests\Web\PartialCancellationRequest;
use App\Http\Requests\Web\ProofOfSendingRequest;
use App\Http\Requests\Web\ReportProblemRequest;
use App\Http\Support\OrderPageProps;
use App\Http\Support\PriceDisplay;
use App\Models\Order;
use App\Models\Rma;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            'cancel_items_url' => route('orders.guest.cancel-items', ['order' => $order, 'expires' => $expires, 'signature' => $signature]),
            'cancel_undispatched_items_url' => $model->company_id === null
                ? route('orders.guest.cancel-undispatched-items', ['order' => $order, 'expires' => $expires, 'signature' => $signature]) : null,
            'problems_url' => route('orders.guest.problems', ['order' => $order, 'expires' => $expires, 'signature' => $signature]),
            'returns_url' => route('orders.guest', ['order' => $order, 'expires' => $expires, 'signature' => $signature]).'/returns',
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

    /** 05.4 §13.3: a guest cancels items of a dispatched order, by their order link. */
    public function cancelItems(CancellationItemsRequest $request, string $order, int $expires, string $signature): RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }

        try {
            $rma = (new ConsumerCancellations)->request($model->id, $request->packQtyByLineNo(), CarbonImmutable::now());
        } catch (CancellationRequestRejectedException $e) {
            return back()->withErrors([$e->field ?? 'lines' => $e->getMessage()]);
        }

        return back()->with('status', "Cancellation {$rma->rma_number} confirmed. We have emailed you how to send the items back.");
    }

    /** 05.10 §2: a valid signed guest link grants access to this one consumer order. */
    public function cancelUndispatchedItems(PartialCancellationRequest $request, string $order, int $expires, string $signature): RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }
        if ($model->company_id !== null) {
            abort(404);
        }

        try {
            (new PartialCancellations)->cancel($model->id, $request->packQtyByLineNo(), 'customer', null,
                CarbonImmutable::now(), clientToken: $request->clientToken());
        } catch (OrderNotCancellableException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return back()->with('status', 'The selected items have been cancelled. We have emailed you a confirmation.');
    }

    /** 05.4 §13.4: a guest reports faulty, damaged or wrong goods, by their order link. */
    public function reportProblem(ReportProblemRequest $request, string $order, int $expires, string $signature): RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }

        try {
            $rma = (new FaultReports)->report($model->id, $request->packQtyByLineNo(), (string) $request->validated('reason'), (string) $request->validated('detail'), $request->photos(), CarbonImmutable::now(), null, $request->validated('customer_choice'));
        } catch (CancellationRequestRejectedException $e) {
            return back()->withErrors([$e->field ?? 'lines' => $e->getMessage()]);
        }

        return back()->with('status', "Thank you — we have your report {$rma->rma_number} and will be in touch.");
    }

    /** 05.4 §13.5: a guest uploads proof of sending, by their order link. */
    public function uploadProof(ProofOfSendingRequest $request, string $order, int $expires, string $signature, string $rma): RedirectResponse
    {
        $model = $this->linkedOrder($order, $expires, $signature);
        if ($model === null) {
            return redirect()->route('orders.lookup')->with('status', self::INVALID);
        }
        $return = Rma::query()->where('public_id', $rma)->where('order_id', $model->id)->firstOrFail();

        try {
            (new ProofOfSending)->upload($return->id, $request->file('proof'), null);
        } catch (ReturnActionRefusedException $e) {
            return back()->withErrors(['proof' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you — we have your proof of sending. We refund within 14 days of it.');
    }

    private function linkedOrder(string $publicId, int $expires, string $signature): ?Order
    {
        $model = Order::query()->where('public_id', $publicId)->first();

        return $model !== null && GuestOrderLink::isValid($model, $expires, $signature) ? $model : null;
    }
}
