<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Exceptions\InsufficientCreditException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutPreviewService;
use App\Domain\Ordering\CheckoutRequest;
use App\Domain\Ordering\CheckoutService;
use App\Domain\Ordering\Exceptions\BatchTrackedCheckoutNotSupportedException;
use App\Domain\Ordering\Exceptions\PriceChangedException;
use App\Domain\Ordering\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\CheckoutPreviewRequest;
use App\Http\Requests\Api\V1\PlaceOrderRequest;
use App\Http\Resources\Api\V1\CheckoutPreviewResource;
use App\Http\Support\CartContext;
use App\Http\Support\Idempotency;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

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
                paymentMethod: $method->strategyValue(),
                expectedTotalGrossMinor: $request->expectedTotalGrossMinor(),
                deliveryCountryCode: $address->countryCode,
                customerReference: $request->customerReference(),
                deliveryAddress: $address,
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
        } catch (BatchTrackedCheckoutNotSupportedException) {
            throw new ApiException(422, 'batch_tracked_not_supported', 'An item in your cart cannot be checked out online yet.');
        }

        // `orders` has no payment-method column; the confirmation page reads
        // it from here for the "what happens next" text.
        $request->session()->put("checkout.payment_method.{$order->public_id}", $method->value);

        return response()->json(['data' => [
            'id' => $order->public_id,
            'order_number' => $order->order_number,
            'total_gross_minor' => $order->total_gross_minor,
            'confirmation_url' => route('orders.confirmation', $order->public_id),
        ]], 201);
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
