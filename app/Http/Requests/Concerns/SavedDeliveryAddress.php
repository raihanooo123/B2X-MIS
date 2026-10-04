<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Storefront\PublicCustomer;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/** A saved address supplies editable fields, never authority to another owner's row. */
trait SavedDeliveryAddress
{
    /** @return array<mixed> */
    private function savedAddressRules(): array
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->hasVerifiedEmail() || ! PublicCustomer::eligible($user)) {
            return ['prohibited'];
        }

        return ['nullable', 'string', 'ulid', Rule::exists('addresses', 'public_id')->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)->whereNull('company_id')->whereNull('deleted_at')->where('address_type', 'delivery'))];
    }
}
