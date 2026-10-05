<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\PartialCancellations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\PartialCancellationRequest;
use App\Http\Support\OrderPageProps;
use App\Models\Order;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** 05.10 §2: accounts records a customer's pre-dispatch cancellation instruction. */
class OrderCancellationPageController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('recordUndispatchedCancellation', Order::class);
        $number = $request->query('order');
        $order = is_string($number) && trim($number) !== ''
            ? Order::query()->where('order_number', strtoupper(trim($number)))->first()
            : null;

        return Inertia::render('Staff/OrderCancellations', [
            'order' => $order === null ? null : OrderPageProps::for($order),
            'store_url' => $order === null ? null : route('staff.order-cancellations.store', ['order' => $order->public_id]),
            'search' => is_string($number) ? $number : '',
        ]);
    }

    public function store(PartialCancellationRequest $request, string $order): RedirectResponse
    {
        Gate::authorize('recordUndispatchedCancellation', Order::class);
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        $reason = $request->validated('reason_detail');
        $notified = $request->validated('customer_notified_at');
        if (! is_string($reason) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason_detail' => 'Record the customer’s reason or instruction.']);
        }
        if (! is_string($notified) || $notified === '') {
            throw ValidationException::withMessages(['customer_notified_at' => 'Record when the customer told us.']);
        }
        $notifiedAt = CarbonImmutable::parse($notified, DisplayTime::zone())->utc();
        if ($notifiedAt->isFuture()) {
            throw ValidationException::withMessages(['customer_notified_at' => 'The notification time cannot be in the future.']);
        }

        try {
            (new PartialCancellations)->cancel(
                $model->id,
                $request->packQtyByLineNo(),
                'staff',
                $request->user()?->id,
                $notifiedAt,
                reasonCode: $request->validated('reason_code'),
                reasonDetail: $reason,
                clientToken: $request->clientToken(),
            );
        } catch (OrderNotCancellableException $error) {
            return back()->withErrors(['lines' => $error->getMessage()]);
        }

        return back()->with('status', 'The cancellation was recorded and the customer was notified.');
    }
}
