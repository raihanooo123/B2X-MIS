<?php

namespace App\Policies;

use App\Models\Cart;
use App\Models\CompanyUser;
use App\Models\User;

/**
 * Doc 06 §10. Tenancy is enforced first in the query — CartController
 * only ever loads the caller's own cart (CartService::findCartFor()) and
 * finds a line by `public_id` *within* that cart — so a foreign cart or
 * line id is a 404 before this policy runs. This is the second,
 * independent check on the cart that query produced.
 *
 * `?User` throughout: guests have carts too (02 §14.3). A guest may act
 * only on a cart with no company and no user; *which* guest cart is
 * decided by the session token in the query, which a policy cannot see.
 */
class CartPolicy
{
    /**
     * Reading "my cart" when none exists yet — nothing to check against.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Cart $cart): bool
    {
        return $this->owns($user, $cart);
    }

    /**
     * Adding, changing or removing lines, and bulk-add.
     */
    public function update(?User $user, Cart $cart): bool
    {
        return $this->owns($user, $cart);
    }

    /**
     * Pricing the cart for checkout preview. Same ownership rule as
     * reading it: a preview reveals nothing the cart itself doesn't.
     */
    public function previewCheckout(?User $user, Cart $cart): bool
    {
        return $this->owns($user, $cart);
    }

    private function owns(?User $user, Cart $cart): bool
    {
        if ($user === null) {
            return $cart->company_id === null && $cart->user_id === null;
        }

        if ($cart->company_id !== null) {
            return CompanyUser::query()
                ->where('company_id', $cart->company_id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return $cart->user_id === $user->id;
    }
}
