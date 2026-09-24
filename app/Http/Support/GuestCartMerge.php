<?php

namespace App\Http\Support;

use App\Domain\Identity\CompanyMemberships;
use App\Domain\Ordering\CartOwnerResolver;
use App\Domain\Ordering\CartService;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * When the guest cart is merged into a signed-in user's cart (05.13 §8.4).
 *
 * The merge target depends on which company the user acts for, so:
 *
 *   - zero or one company → merge at sign-in (MergeGuestCartOnLogin);
 *   - several companies   → leave the guest token in the session until
 *     the company is chosen; CompanyChoiceController then merges into the
 *     chosen company's cart. Switching company later never merges again,
 *     because the token is pulled the first time.
 *
 * The merge rules themselves are CartService::mergeGuestCart().
 */
final class GuestCartMerge
{
    public function __construct(
        private readonly CartService $cartService = new CartService,
        private readonly CartOwnerResolver $ownerResolver = new CartOwnerResolver,
    ) {}

    public function atSignIn(Session $session, User $user): void
    {
        if (count(CompanyMemberships::ids($user)) > 1) {
            return;
        }

        $this->merge($session, $user);
    }

    public function afterCompanyChoice(Session $session, User $user): void
    {
        $this->merge($session, $user);
    }

    private function merge(Session $session, User $user): void
    {
        $token = $session->get(CartContext::SESSION_KEY);
        if (! is_string($token) || $token === '') {
            return;
        }

        $owner = $this->ownerResolver->forUser($user, ActingCompany::chosenId($session, $user));

        // Pulled only once the owner resolved — the token stays put if a
        // choice is still due.
        $session->forget(CartContext::SESSION_KEY);

        $this->cartService->mergeGuestCart($token, $owner);
    }
}
