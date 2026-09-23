<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ordering\CartService;
use App\Domain\Ordering\CheckoutPreviewService;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\CheckoutPreviewRequest;
use App\Http\Resources\Api\V1\CheckoutPreviewResource;
use App\Http\Support\CartContext;
use App\Models\Address;
use App\Models\Cart;
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
 * POST /checkout itself is not part of this controller yet.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutPreviewService $previewService = new CheckoutPreviewService,
        private readonly CartService $cartService = new CartService,
        private readonly CartContext $context = new CartContext,
    ) {}

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

        $preview = $this->previewService->preview($cart, $companyId, $countryCode, $request->fulfilmentType());

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
