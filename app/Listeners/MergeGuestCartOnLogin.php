<?php

namespace App\Listeners;

use App\Domain\Ordering\CartOwnerResolver;
use App\Domain\Ordering\CartService;
use App\Http\Support\CartContext;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Guest cart merge at login (02 §14.3). Runs synchronously on the
 * `Login` event, inside the login request, so the buyer's first page
 * after signing in already shows the merged cart.
 *
 * The guest token is forgotten afterwards whatever the outcome: from
 * here on the cart is found by company/user, and a stale token left in
 * the session would silently start a fresh guest cart after logout.
 *
 * The merge rules themselves live in CartService::mergeGuestCart(). The
 * wider login flow (05.13 auth/onboarding, ROADMAP §23) is still
 * unwritten; this covers only the cart half of it.
 */
final class MergeGuestCartOnLogin
{
    public function __construct(
        private readonly CartService $cartService = new CartService,
        private readonly CartOwnerResolver $ownerResolver = new CartOwnerResolver,
    ) {}

    public function handle(Login $event): void
    {
        // Resolved per event, not constructor-injected: a listener instance
        // can outlive the request it was built in (Octane, queue workers).
        $request = request();

        if (! $event->user instanceof User || ! $request->hasSession()) {
            return;
        }

        $token = $request->session()->pull(CartContext::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return;
        }

        $this->cartService->mergeGuestCart($token, $this->ownerResolver->forUser($event->user));
    }
}
