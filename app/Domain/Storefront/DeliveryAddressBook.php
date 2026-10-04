<?php

namespace App\Domain\Storefront;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Owner lock serialises first address, default changes and default promotion. */
final class DeliveryAddressBook
{
    /** @param array<string, mixed> $fields */
    public function save(User $user, array $fields, ?string $publicId = null): Address
    {
        return DB::transaction(function () use ($user, $fields, $publicId): Address {
            User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('create', Address::class);
            $address = $publicId === null ? new Address : $this->owned($user, $publicId);
            if ($publicId !== null) {
                Gate::forUser($user)->authorize('update', $address);
            }
            $default = ($fields['is_default'] ?? false) || $address->getAttribute('is_default') || ! Address::query()->where('user_id', $user->id)->exists();
            unset($fields['is_default']);
            $address->fill($fields);
            $address->fill(['company_id' => null, 'user_id' => $user->id, 'address_type' => 'delivery', 'delivery_zone_id' => null]);
            if ($default) {
                Address::query()->where('user_id', $user->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $address->is_default = $default;
            $address->save();

            return $address;
        });
    }

    public function makeDefault(User $user, string $publicId): void
    {
        DB::transaction(function () use ($user, $publicId): void {
            User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $address = $this->owned($user, $publicId);
            Gate::forUser($user)->authorize('update', $address);
            Address::query()->where('user_id', $user->id)->where('is_default', true)->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });
    }

    public function delete(User $user, string $publicId): void
    {
        DB::transaction(function () use ($user, $publicId): void {
            User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $address = $this->owned($user, $publicId);
            Gate::forUser($user)->authorize('delete', $address);
            $default = $address->is_default;
            $address->delete();
            if ($default) {
                Address::query()->where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    public function owned(User $user, string $publicId): Address
    {
        return Address::query()->whereNull('company_id')->where('user_id', $user->id)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    public function listing(User $user): array
    {
        return array_values(Address::query()->whereNull('company_id')->where('user_id', $user->id)
            ->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Address $a) => $a->only(['public_id', 'label', 'contact_name', 'phone', 'line1', 'line2', 'city', 'county', 'postcode', 'country_code', 'is_default']))->values()->all());
    }
}
