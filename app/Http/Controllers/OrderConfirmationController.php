<?php

namespace App\Http\Controllers;

use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\OrderCancellationService;
use App\Http\Support\OrderPageProps;
use App\Http\Support\PriceDisplay;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order confirmation page for a signed-in buyer (OrderPolicy). A
 * guest's order page is GuestOrderController, by signed link. The order
 * itself is OrderPageProps: the order's own snapshot, never re-resolved.
 */
class OrderConfirmationController extends Controller
{
    public function show(Request $request, string $order): Response
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        Gate::authorize('view', $model);

        return Inertia::render('Orders/Confirmation', [
            'display_mode' => PriceDisplay::checkoutMode($request),
            'order' => OrderPageProps::for($model),
            'guest' => null,
            'cancel_url' => OrderPageProps::canCancel($model) && Gate::allows('cancel', $model) ? route('orders.cancel', ['order' => $model->public_id]) : null,
        ]);
    }

    /** 05.4 §13.2: a signed-in consumer cancels before dispatch. */
    public function cancel(Request $request, string $order): RedirectResponse
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        Gate::authorize('cancel', $model);

        try {
            (new OrderCancellationService)->cancel($model->id, $request->user()?->getAuthIdentifier());
        } catch (OrderNotCancellableException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', 'Your order is cancelled. We have emailed you a confirmation.');
    }
}
