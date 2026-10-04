<?php

namespace App\Http\Controllers;

use App\Http\Support\OrderPageProps;
use App\Http\Support\PriceDisplay;
use App\Models\Order;
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
        ]);
    }
}
