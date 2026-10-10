<?php

namespace App\Http\Controllers\Api\V1\Trade;

use App\Domain\Billing\CardIntent;
use App\Domain\Billing\CardPayments;
use App\Domain\Billing\DeclineMessages;
use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Collection\SlotUnavailable;
use App\Domain\Credit\ApprovedOrderPayment;
use App\Domain\Credit\TradeApprovals;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\NoEligibleBatchException;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\Credit\PayApprovedOrderRequest;
use App\Http\Support\Idempotency;
use App\Http\Support\TradeContext;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 05.2 §8.1 row 3, §18.1 — the buyer's side of a trade order that went
 * for approval:
 *
 *   POST /api/v1/orders/{id}/pay-in-advance  a credit shortfall paid by
 *        card instead (stock reserved now; pay once any buyer approval
 *        is given);
 *   POST /api/v1/orders/{id}/card-intent      a manual-capture
 *        authorisation for the approved order's total (07 §6.4);
 *   POST /api/v1/orders/{id}/pay              record it and confirm the
 *        order, then capture after commit — exactly as at checkout.
 */
class OrderPaymentController extends Controller
{
    public function __construct(private readonly ApprovedOrderPayment $payments = new ApprovedOrderPayment) {}

    public function payInAdvance(Request $request, string $order): JsonResponse
    {
        return Idempotency::run($request, 'order-pay-in-advance', function () use ($request, $order): JsonResponse {
            [$user] = TradeContext::resolve($request);
            $record = $this->order($request, $order);
            try {
                $updated = (new TradeApprovals)->payInAdvance($record->id, $user);
            } catch (InsufficientStockException|NoEligibleBatchException) {
                throw new ApiException(409, 'insufficient_stock', 'Some items are no longer in stock. Place the order again with what is available.');
            } catch (SlotUnavailable $e) {
                throw new ApiException(409, 'slot_unavailable', $e->getMessage());
            }

            return response()->json(['data' => ['id' => $updated->public_id, 'status' => $updated->status, 'payment_method' => $updated->payment_method]]);
        });
    }

    public function cardIntent(Request $request, string $order): JsonResponse
    {
        [$user] = TradeContext::resolve($request);
        $record = $this->order($request, $order);
        $this->payments->assertPayable($record, $user);
        if ((string) config('services.stripe.secret') === '') {
            throw new ApiException(503, 'card_payments_unavailable', 'Card payments are not available right now. Please contact us to pay.');
        }

        // A fresh intent per attempt: one never confirmed holds nothing and lapses.
        $intent = $this->gatewayCall(fn () => $this->gateway()->createAuthorisation(
            $record->total_gross_minor, 'GBP',
            ['order_id' => $record->public_id, 'user_id' => (string) $user->public_id],
            'approved-order:'.$record->public_id.':'.Str::ulid(),
        ));

        return response()->json(['data' => [
            'id' => $intent->id,
            'client_secret' => $intent->clientSecret,
            'status' => $intent->status,
            'amount_minor' => $intent->amountMinor,
        ]]);
    }

    public function pay(PayApprovedOrderRequest $request, string $order): JsonResponse
    {
        return Idempotency::run($request, 'order-pay', function () use ($request, $order): JsonResponse {
            [$user] = TradeContext::resolve($request);
            $record = $this->order($request, $order);
            $this->payments->assertPayable($record, $user);

            if (CardPayments::findByIntent($request->paymentIntentId()) !== null) {
                throw new ApiException(409, 'payment_already_used', 'This payment has already been used for an order.');
            }
            $intent = $this->gatewayCall(fn () => $this->gateway()->retrieve($request->paymentIntentId()));
            $this->assertIntentFor($intent, $record, (string) $user->public_id);

            try {
                $confirmed = $this->payments->confirm($record->id, $user, $intent);
            } catch (Throwable $e) {
                $this->release($intent->id);
                throw $e;
            }

            $this->capture($intent->id, $confirmed);

            return response()->json(['data' => [
                'id' => $confirmed->public_id,
                'status' => $confirmed->status,
                'payment_status' => Order::query()->where('id', $confirmed->id)->value('payment_status'),
                'confirmation_url' => route('orders.confirmation', $confirmed->public_id),
            ]]);
        });
    }

    private function order(Request $request, string $publicId): Order
    {
        [, $company] = TradeContext::resolve($request);

        return Order::query()->where('public_id', $publicId)->where('company_id', $company->id)->firstOrFail();
    }

    private function assertIntentFor(CardIntent $intent, Order $order, string $userRef): void
    {
        if (($intent->metadata['order_id'] ?? null) !== $order->public_id || ($intent->metadata['user_id'] ?? null) !== $userRef) {
            throw new ApiException(422, 'payment_not_authorised', 'This payment does not belong to this order.');
        }
        if (! $intent->isAuthorised()) {
            throw new ApiException(422, 'card_declined', DeclineMessages::for($intent->declineCode), [[
                'field' => 'payment_intent_id', 'code' => 'card_declined', 'message' => DeclineMessages::for($intent->declineCode),
            ]]);
        }
    }

    /** After commit (04 §4.4); failures are reconciliation's, never a lost order. */
    private function capture(string $intentId, Order $order): void
    {
        try {
            $captured = $this->gateway()->capture($intentId);
            CardPayments::markCaptured($intentId, $captured);
        } catch (Throwable $e) {
            if ($e instanceof PaymentGatewayException) {
                CardPayments::markCaptureFailed($intentId, $e->getMessage());
            }
            Log::critical('Approved trade order authorised but capture not completed — reconcile.', ['payment_intent' => $intentId, 'order' => $order->order_number, 'error' => $e->getMessage()]);
        }
    }

    private function release(string $intentId): void
    {
        try {
            $this->gateway()->cancel($intentId);
            CardPayments::markVoided($intentId);
        } catch (PaymentGatewayException $e) {
            Log::warning('Could not release a card authorisation.', ['payment_intent' => $intentId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function gatewayCall(callable $call): mixed
    {
        try {
            return $call();
        } catch (PaymentGatewayException $e) {
            Log::error('Card payment gateway error.', ['error' => $e->getMessage()]);

            throw new ApiException(502, 'payment_gateway_unavailable', 'We could not reach our card processor. Your card has not been charged — please try again.');
        }
    }

    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }
}
