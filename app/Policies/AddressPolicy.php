<?php

namespace App\Policies;

use App\Domain\Storefront\PublicCustomer;
use App\Models\Address;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Public address book only; company address permissions stay separate. */
final class AddressPolicy
{
    public function viewAny(User $user): bool
    {
        return PublicCustomer::eligible($user) && $user->hasVerifiedEmail();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Address $address): Response
    {
        return $this->viewAny($user) && $address->company_id === null && $address->user_id === $user->id && ! $address->trashed()
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Address $address): Response
    {
        return $this->update($user, $address);
    }
}
