<?php

namespace App\Http\Controllers;

use App\Domain\Ordering\Exceptions\OrderNotCancellableException;
use App\Domain\Ordering\OrderCancellationService;
use App\Domain\Ordering\PartialCancellations;
use App\Domain\Returns\ConsumerCancellations;
use App\Domain\Returns\Exceptions\CancellationRequestRejectedException;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\FaultReports;
use App\Domain\Returns\ProofOfSending;
use App\Http\Requests\Web\CancellationItemsRequest;
use App\Http\Requests\Web\PartialCancellationRequest;
use App\Http\Requests\Web\ProofOfSendingRequest;
use App\Http\Requests\Web\ReportProblemRequest;
use App\Http\Support\OrderPageProps;
use App\Http\Support\PriceDisplay;
use App\Models\Order;
use App\Models\Rma;
use Carbon\CarbonImmutable;
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
            'cancel_items_url' => Gate::allows('cancel', $model) ? route('orders.cancel-items', ['order' => $model->public_id]) : null,
            'cancel_undispatched_items_url' => Gate::allows('cancelUndispatchedItems', $model)
                ? route('orders.cancel-undispatched-items', ['order' => $model->public_id]) : null,
            'problems_url' => Gate::allows('cancel', $model) ? route('orders.problems', ['order' => $model->public_id]) : null,
            // 05.4 §13.5: proof uploads post to {returns_url}/{rma id}/proof.
            'returns_url' => Gate::allows('cancel', $model) ? url("/orders/{$model->public_id}/returns") : null,
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

    /** 05.4 §13.3: a signed-in consumer cancels items of a dispatched order. */
    public function cancelItems(CancellationItemsRequest $request, string $order): RedirectResponse
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        Gate::authorize('cancel', $model);

        try {
            $rma = (new ConsumerCancellations)->request($model->id, $request->packQtyByLineNo(), CarbonImmutable::now(), $request->user()?->getAuthIdentifier());
        } catch (CancellationRequestRejectedException $e) {
            return back()->withErrors([$e->field ?? 'lines' => $e->getMessage()]);
        }

        return back()->with('status', "Cancellation {$rma->rma_number} confirmed. We have emailed you how to send the items back.");
    }

    /** 05.10 §2: the buyer, trade owner or approver cancels whole packs before dispatch. */
    public function cancelUndispatchedItems(PartialCancellationRequest $request, string $order): RedirectResponse
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        Gate::authorize('cancelUndispatchedItems', $model);

        $reason = $request->validated('reason_detail');
        if ($model->company_id !== null && (! is_string($reason) || trim($reason) === '')) {
            return back()->withErrors(['reason_detail' => 'Give a reason for cancelling items from a trade order.']);
        }

        try {
            (new PartialCancellations)->cancel($model->id, $request->packQtyByLineNo(), 'customer', $request->user()?->id,
                CarbonImmutable::now(), reasonDetail: is_string($reason) ? $reason : null, clientToken: $request->clientToken());
        } catch (OrderNotCancellableException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return back()->with('status', 'The selected items have been cancelled. We have emailed you a confirmation.');
    }

    /** 05.4 §13.4: a signed-in consumer reports faulty, damaged or wrong goods. */
    public function reportProblem(ReportProblemRequest $request, string $order): RedirectResponse
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        Gate::authorize('cancel', $model);

        try {
            $rma = (new FaultReports)->report($model->id, $request->packQtyByLineNo(), (string) $request->validated('reason'), (string) $request->validated('detail'), $request->photos(), CarbonImmutable::now(), $request->user()?->getAuthIdentifier(), $request->validated('customer_choice'));
        } catch (CancellationRequestRejectedException $e) {
            return back()->withErrors([$e->field ?? 'lines' => $e->getMessage()]);
        }

        return back()->with('status', "Thank you — we have your report {$rma->rma_number} and will be in touch.");
    }

    /** 05.4 §13.5: a signed-in consumer uploads proof of sending for one of their returns. */
    public function uploadProof(ProofOfSendingRequest $request, string $order, string $rma): RedirectResponse
    {
        $model = Order::query()->where('public_id', $order)->firstOrFail();
        Gate::authorize('cancel', $model);
        $return = Rma::query()->where('public_id', $rma)->where('order_id', $model->id)->firstOrFail();

        try {
            (new ProofOfSending)->upload($return->id, $request->file('proof'), $request->user()?->getAuthIdentifier());
        } catch (ReturnActionRefusedException $e) {
            return back()->withErrors(['proof' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you — we have your proof of sending. We refund within 14 days of it.');
    }
}
