<?php

namespace App\Http\Support;

use App\Domain\Ordering\CartOwner;
use App\Domain\Ordering\CartOwnerResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolves the CartOwner for an API request: the authenticated user's
 * company/user identity, or a guest session token.
 *
 * The guest token is a random 64-character string kept in the server
 * side session — never the Laravel session id itself, which is
 * regenerated at login, and never sent to the client. Because it lives
 * in the session, it survives `session()->regenerate()`, which is what
 * lets MergeGuestCartOnLogin find the guest cart after login.
 */
final class CartContext
{
    public const SESSION_KEY = 'cart.session_token';

    public function __construct(
        private readonly CartOwnerResolver $ownerResolver = new CartOwnerResolver,
    ) {}

    /**
     * `$createGuestToken = false` for reads: a guest who has never
     * written to a cart gets no token and no cart row.
     */
    public function owner(Request $request, bool $createGuestToken): ?CartOwner
    {
        $user = $request->user();
        if ($user instanceof User) {
            $chosen = $request->hasSession() ? ActingCompany::chosenId($request->session(), $user) : null;

            return $this->ownerResolver->forUser($user, $chosen);
        }

        $session = $request->session();
        $token = $session->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            if (! $createGuestToken) {
                return null;
            }

            $token = Str::random(64);
            $session->put(self::SESSION_KEY, $token);
        }

        return CartOwner::guest($token);
    }
}
