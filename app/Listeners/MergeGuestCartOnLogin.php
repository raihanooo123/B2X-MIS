<?php

namespace App\Listeners;

use App\Http\Support\GuestCartMerge;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Guest cart merge at sign-in (02 §14.3, 05.13 §8). Runs synchronously on
 * the `Login` event, inside the sign-in request, so the buyer's first page
 * after signing in already shows the merged cart. SignIn fires `Login`
 * only after the second factor (05.13 §6.1), so a password alone never
 * moves a cart onto an account.
 *
 * For a user in several companies the merge waits for the company choice
 * (05.13 §8.4) — GuestCartMerge decides; the rules themselves live in
 * CartService::mergeGuestCart().
 */
final class MergeGuestCartOnLogin
{
    public function __construct(
        private readonly GuestCartMerge $merge = new GuestCartMerge,
    ) {}

    public function handle(Login $event): void
    {
        // Resolved per event, not constructor-injected: a listener instance
        // can outlive the request it was built in (Octane, queue workers).
        $request = request();

        if (! $event->user instanceof User || ! $request->hasSession()) {
            return;
        }

        $this->merge->atSignIn($request->session(), $event->user);
    }
}
