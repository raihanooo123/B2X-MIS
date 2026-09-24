<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\CardIntent;
use App\Domain\Billing\CardPayments;
use App\Domain\Billing\DeclineMessages;
use App\Domain\Billing\Exceptions\PaymentGatewayException;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutPreviewService;
use App\Domain\Ordering\CheckoutRequest;
use App\Domain\Ordering\CheckoutService;
use App\Domain\Ordering\DeliveryAddress;
use App\Domain\Ordering\Exceptions\BatchTrackedCheckoutNotSupportedException;
use App\Domain\Ordering\Exceptions\PriceChangedException;
use App\Domain\Ordering\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\CardIntentRequest;
use App\Http\Requests\Api\V1\CheckoutPreviewRequest;
use App\Http\Requests\Api\V1\PlaceOrderRequest;
use App\Http\Resources\Api\V1\CheckoutPreviewResource;
use App\Http\Support\CartContext;
use App\Http\Support\Idempotency;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Order;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Doc 06 §9.2 — POST /api/v1/checkout/preview. Side-effect-free: it
 * never creates a cart (a caller without one previews an empty cart and
 * gets a `cart_empty` blocker), never creates a guest token, and
 * CheckoutPreviewService writes nothing.
 *
 * Returns 200 even when blocked — the blockers *are* the answer (06
 * §9.2). Only an unresolvable delivery country is a 422, because
 * without one there is no tax rate and so no total to show.
 *
 * POST /checkout (06 §9.3) places the order: idempotent (06 §6), refused
 * with the preview's own blockers while any exist, and 409 `price_changed`
 * — with both totals — when the server's re-resolution disagrees with the
 * total the buyer confirmed. Nothing is committed in either case.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutPreviewService $previewService = new CheckoutPreviewService,
        private readonly CartService $cartService = new CartService,
        private readonly CartContext $context = new CartContext,
        private readonly CheckoutService $checkoutService = new CheckoutService,
    ) {}

    /** Resolved on use, so requests that never touch cards never build a Stripe client. */
    private function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }

    public function store(PlaceOrderRequest $request): JsonResponse
    {
        return Idempotency::run($request, 'checkout', fn () => $this->placeOrder($request));
    }

    private function placeOrder(PlaceOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException(401, 'unauthenticated', 'Sign in to check out.');
        }

        $owner = $this->context->owner($request, createGuestToken: false);
        $cart = $owner === null ? null : $this->cartService->findCartFor($owner);
        if ($owner === null || $cart === null) {
            throw new ApiException(422, 'cart_empty', 'Your cart is empty.');
        }
        Gate::authorize('checkout', $cart);

        $companyId = $owner->companyId;
        $method = $request->paymentMethod();
        $this->assertPaymentMethodAllowed($method, $companyId);

        $address = $request->deliveryAddress();

        // Card (07 §6.4, 04 §4.4): the browser has already authorised the
        // amount through Stripe Elements. Verify that authorisation before
        // anything else; release it if the order is not placed; capture it
        // only after the order has committed.
        $card = $method === PaymentMethod::Card ? $this->authorisedCard($request, $user, $cart) : null;

        try {
            $order = $this->commitOrder($request, $user, $cart, $companyId, $method, $address, $card);
        } catch (Throwable $e) {
            if ($card !== null && ! ($e instanceof ApiException && $e->errorCode === 'payment_already_used')) {
                $this->releaseAuthorisation($card->id);
            }
            throw $e;
        }

        if ($card !== null) {
            $this->capture($card->id);
            Cache::forget($this->cardIntentCacheKey($user, $cart));
        }

        $order->refresh();

        return response()->json(['data' => [
            'id' => $order->public_id,
            'order_number' => $order->order_number,
            'total_gross_minor' => $order->total_gross_minor,
            'payment_status' => $order->payment_status,
            'confirmation_url' => route('orders.confirmation', $order->public_id),
        ]], 201);
    }

    /**
     * The preview's checks, then the order itself (CheckoutService). A card
     * authorisation, when given, is recorded in the order's transaction.
     */
    private function commitOrder(PlaceOrderRequest $request, User $user, Cart $cart, ?int $companyId, PaymentMethod $method, DeliveryAddress $address, ?CardIntent $card): Order
    {
        // The same checks preview reports — the button the buyer pressed was
        // only enabled because there were none, but the cart, the stock or
        // the account may have changed since.
        $preview = $this->previewService->preview($cart, $companyId, $address->countryCode, 'delivery', user: $user, checkIdentity: true);
        if ($preview->blockers !== []) {
            throw new ApiException(422, 'checkout_blocked', 'This order cannot be placed yet.', array_map(fn ($b) => $b->toArray(), $preview->blockers));
        }

        try {
            $order = $this->checkoutService->checkout(new CheckoutRequest(
                cartId: $cart->id,
                companyId: $companyId,
                userId: $user->id,
                paymentMethod: $method->value,
                expectedTotalGrossMinor: $request->expectedTotalGrossMinor(),
                deliveryCountryCode: $address->countryCode,
                customerReference: $request->customerReference(),
                deliveryAddress: $address,
                cardAuthorisation: $card,
            ));
        } catch (PriceChangedException $e) {
            throw new ApiException(409, 'price_changed', 'Prices have changed since you reviewed your order. Nothing has been placed.', [[
                'field' => 'expected_total_gross_minor',
                'code' => 'price_changed',
                'message' => 'Review the new total and place the order again.',
                'meta' => [
                    'expected_total_gross_minor' => $e->expectedTotalGrossMinor,
                    'actual_total_gross_minor' => $e->actualTotalGrossMinor,
                ],
            ]]);
        } catch (InsufficientCreditException $e) {
            throw new ApiException(422, 'insufficient_credit', 'This order is more than your available credit. Pay by card or bank transfer instead.', [[
                'field' => 'payment_method',
                'code' => 'insufficient_credit',
                'message' => 'Not enough credit available.',
                'meta' => ['required_minor' => $e->requiredCreditMinor, 'available_minor' => $e->availableCreditMinor],
            ]]);
        } catch (InsufficientStockException $e) {
            $codes = Sku::query()->whereIn('id', array_map(fn ($s) => $s->skuId, $e->shortfalls))->pluck('sku_code', 'id');

            throw new ApiException(409, 'insufficient_stock', 'Some items sold out while you were checking out. Nothing has been placed.', array_map(fn ($s) => [
                'field' => null,
                'code' => 'insufficient_stock',
                'message' => "Only {$s->availableBaseQty} units of ".($codes[$s->skuId] ?? 'an item').' are available.',
                'meta' => ['sku_code' => $codes[$s->skuId] ?? null, 'requested_base_qty' => $s->requestedBaseQty, 'available_base_qty' => $s->availableBaseQty],
            ], $e->shortfalls));
        } catch (QueryException $e) {
            // Two orders racing for one authorisation: the second loses on
            // payments_gateway_reference_uq and rolls back (02 §14.5.1).
            if (str_contains($e->getMessage(), 'payments_gateway_reference_uq')) {
                throw new ApiException(409, 'payment_already_used', 'This payment has already been used for an order.');
            }
            throw $e;
        } catch (BatchTrackedCheckoutNotSupportedException) {
            throw new ApiException(422, 'batch_tracked_not_supported', 'An item in your cart cannot be checked out online yet.');
        }

        return $order;
    }

    /**
     * POST /api/v1/checkout/card-intent — a manual-capture authorisation for
     * the previewed total (07 §6.4). The browser confirms it with Stripe
     * Elements; the card never touches this server.
     *
     * No double charge on retry: one intent per buyer and cart is kept (in
     * the cache) and reused while it can still be — including one already
     * authorised whose order never got placed, which the browser then skips
     * straight to placing. An intent for a different total is released and
     * replaced.
     */
    public function cardIntent(CardIntentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException(401, 'unauthenticated', 'Sign in to check out.');
        }
        if ((string) config('services.stripe.secret') === '') {
            throw new ApiException(503, 'card_payments_unavailable', 'Card payments are not available right now. Please pay by bank transfer.');
        }

        $owner = $this->context->owner($request, createGuestToken: false);
        $cart = $owner === null ? null : $this->cartService->findCartFor($owner);
        if ($owner === null || $cart === null) {
            throw new ApiException(422, 'cart_empty', 'Your cart is empty.');
        }
        Gate::authorize('checkout', $cart);

        $preview = $this->previewService->preview($cart, $owner->companyId, $request->deliveryCountryCode(), 'delivery', user: $user, checkIdentity: true);
        if ($preview->blockers !== []) {
            throw new ApiException(422, 'checkout_blocked', 'This order cannot be placed yet.', array_map(fn ($b) => $b->toArray(), $preview->blockers));
        }
        if ($preview->totalGrossMinor !== $request->expectedTotalGrossMinor()) {
            throw $this->priceChanged($request->expectedTotalGrossMinor(), $preview->totalGrossMinor);
        }

        $intent = $this->reusableIntent($user, $cart, $preview->totalGrossMinor)
            ?? $this->gatewayCall(fn () => $this->gateway()->createAuthorisation(
                $preview->totalGrossMinor,
                'GBP',
                ['cart_id' => (string) $cart->public_id, 'user_id' => (string) $user->public_id],
                'card-intent:'.$user->public_id.':'.$cart->public_id.':'.$preview->totalGrossMinor.':'.Str::ulid(),
            ));

        Cache::put($this->cardIntentCacheKey($user, $cart), $intent->id, 86400);

        return response()->json(['data' => [
            'id' => $intent->id,
            'client_secret' => $intent->clientSecret,
            'status' => $intent->status,
            'amount_minor' => $intent->amountMinor,
        ]]);
    }

    private function reusableIntent(User $user, Cart $cart, int $amountMinor): ?CardIntent
    {
        $id = Cache::get($this->cardIntentCacheKey($user, $cart));
        if (! is_string($id) || CardPayments::findByIntent($id) !== null) {
            return null;
        }

        try {
            $intent = $this->gateway()->retrieve($id);
        } catch (PaymentGatewayException) {
            return null;
        }

        if ($intent->isReusable() && $intent->amountMinor === $amountMinor) {
            return $intent;
        }

        // A different total, or no longer usable: release any hold it has.
        if ($intent->isReusable()) {
            $this->releaseAuthorisation($intent->id);
        }

        return null;
    }

    private function cardIntentCacheKey(User $user, Cart $cart): string
    {
        return 'checkout:card-intent:'.$user->public_id.':'.$cart->public_id;
    }

    /**
     * The intent must be this buyer's, for this cart, authorised, and for the
     * total they confirmed. A decline is answered with what to do next.
     */
    private function authorisedCard(PlaceOrderRequest $request, User $user, Cart $cart): CardIntent
    {
        $id = (string) $request->paymentIntentId();

        if (CardPayments::findByIntent($id) !== null) {
            throw new ApiException(409, 'payment_already_used', 'This payment has already been used for an order.');
        }

        $intent = $this->gatewayCall(fn () => $this->gateway()->retrieve($id));

        if (($intent->metadata['cart_id'] ?? null) !== $cart->public_id || ($intent->metadata['user_id'] ?? null) !== $user->public_id) {
            throw new ApiException(422, 'payment_not_authorised', 'This payment does not belong to your order.');
        }

        if (! $intent->isAuthorised()) {
            throw new ApiException(422, 'card_declined', DeclineMessages::for($intent->declineCode), [[
                'field' => 'payment_intent_id',
                'code' => 'card_declined',
                'message' => DeclineMessages::for($intent->declineCode),
                'meta' => ['decline_code' => $intent->declineCode, 'status' => $intent->status],
            ]]);
        }

        if ($intent->amountMinor !== $request->expectedTotalGrossMinor()) {
            $this->releaseAuthorisation($intent->id);

            throw $this->priceChanged($request->expectedTotalGrossMinor(), $intent->amountMinor);
        }

        return $intent;
    }

    /** After commit (04 §4.4). A failed capture leaves the order unpaid for a person to follow up. */
    private function capture(string $intentId): void
    {
        try {
            CardPayments::markCaptured($intentId, $this->gateway()->capture($intentId));
        } catch (PaymentGatewayException $e) {
            CardPayments::markCaptureFailed($intentId, $e->getMessage());
            Log::critical('Card payment authorised but capture failed; order placed unpaid.', ['payment_intent' => $intentId, 'error' => $e->getMessage()]);
        }
    }

    private function releaseAuthorisation(string $intentId): void
    {
        try {
            $this->gateway()->cancel($intentId);
            CardPayments::markVoided($intentId);
        } catch (PaymentGatewayException $e) {
            // The authorisation lapses on its own (Stripe: 7 days); log it.
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

    private function priceChanged(int $expected, int $actual): ApiException
    {
        return new ApiException(409, 'price_changed', 'Prices have changed since you reviewed your order. Nothing has been placed.', [[
            'field' => 'expected_total_gross_minor',
            'code' => 'price_changed',
            'message' => 'Review the new total and place the order again.',
            'meta' => ['expected_total_gross_minor' => $expected, 'actual_total_gross_minor' => $actual],
        ]]);
    }

    /**
     * 05.2 §8.1: on-account needs a company on credit terms; a company on
     * `prepay` terms, and every public customer, pays by card or BACS.
     */
    private function assertPaymentMethodAllowed(PaymentMethod $method, ?int $companyId): void
    {
        if ($method !== PaymentMethod::OnAccount) {
            return;
        }

        $terms = $companyId === null ? null : Company::query()->whereKey($companyId)->value('payment_terms');

        if ($terms === null || $terms === 'prepay') {
            throw new ApiException(422, 'payment_method_not_available', 'On-account payment is not available for this account.', [[
                'field' => 'payment_method',
                'code' => 'payment_method_not_available',
                'message' => 'Pay by card or bank transfer.',
            ]]);
        }
    }

    public function preview(CheckoutPreviewRequest $request): JsonResponse
    {
        $owner = $this->context->owner($request, createGuestToken: false);
        $cart = $owner === null ? null : $this->cartService->findCartFor($owner);

        if ($cart === null) {
            Gate::authorize('viewAny', Cart::class);
        } else {
            Gate::authorize('previewCheckout', $cart);
        }

        $companyId = $owner?->companyId;
        $countryCode = $request->deliveryCountryCode() ?? $this->defaultDeliveryCountry($companyId);

        if ($countryCode === null) {
            throw new ApiException(422, 'delivery_country_required', 'A delivery country is needed to calculate tax.', [[
                'field' => 'delivery_country_code',
                'code' => 'required',
                'message' => 'Send delivery_country_code, or set a default delivery address on the account.',
            ]]);
        }

        $preview = $this->previewService->preview(
            $cart,
            $companyId,
            $countryCode,
            $request->fulfilmentType(),
            user: $request->user() instanceof User ? $request->user() : null,
            checkIdentity: true,
        );

        return (new CheckoutPreviewResource($preview))->response();
    }

    /**
     * The company's default delivery address (`addresses_default_uq`),
     * 'delivery' before 'both'.
     */
    private function defaultDeliveryCountry(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }

        $code = Address::query()
            ->where('company_id', $companyId)
            ->whereIn('address_type', ['delivery', 'both'])
            ->where('is_default', true)
            ->orderByRaw("address_type = 'delivery' DESC")
            ->value('country_code');

        return $code === null ? null : trim((string) $code);
    }
}
