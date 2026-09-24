<?php

namespace App\Domain\Identity;

use App\Models\B2bApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Self-registration, 05.13 §5.1–5.2. Both paths create an `active` user
 * with an unverified email; the trade path also files the 05.2 §5.1
 * application in the same transaction, so neither exists without the
 * other.
 *
 * An email that already has an account never reaches here as an error:
 * the caller sends that address an "you already have an account" email
 * and shows the same confirmation as a successful registration (§5.1,
 * no enumeration).
 */
final class Registration
{
    public static function emailTaken(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /**
     * 05.2 §5.2: "an open application on the same contact_email → block".
     */
    public static function hasOpenApplication(string $email): bool
    {
        return B2bApplication::query()
            ->where('contact_email', $email)
            ->whereIn('status', ['submitted', 'in_review', 'info_requested'])
            ->exists();
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $person
     */
    public static function publicCustomer(array $person): User
    {
        return User::query()->create([
            'email' => $person['email'],
            'password_hash' => Hash::make($person['password']),
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'status' => 'active',
        ]);
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, phone: string}  $person
     * @param  array{company_name: string, registration_number: ?string, vat_number: ?string, business_type: BusinessType, estimated_monthly_spend_minor: ?int, address: array<string, string|null>}  $application
     * @return array{0: User, 1: B2bApplication}
     */
    public static function tradeApplicant(array $person, array $application): array
    {
        return DB::transaction(function () use ($person, $application) {
            $user = User::query()->create([
                'email' => $person['email'],
                'password_hash' => Hash::make($person['password']),
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'phone' => $person['phone'],
                'status' => 'active',
            ]);

            $filed = self::fileApplication($user, $person['phone'], $application);

            return [$user, $filed];
        });
    }

    /**
     * A signed-in user with no company applying (05.13 §5.1, signed-in
     * variant): the application links to their existing account.
     *
     * @param  array{company_name: string, registration_number: ?string, vat_number: ?string, business_type: BusinessType, estimated_monthly_spend_minor: ?int, address: array<string, string|null>}  $application
     */
    public static function fileApplication(User $user, ?string $phone, array $application): B2bApplication
    {
        return B2bApplication::query()->create([
            'applicant_user_id' => $user->id,
            'company_name' => $application['company_name'],
            'registration_number' => $application['registration_number'],
            'vat_number' => $application['vat_number'],
            'contact_name' => trim("{$user->first_name} {$user->last_name}"),
            'contact_email' => $user->email,
            'contact_phone' => $phone,
            'business_type' => $application['business_type']->value,
            'estimated_monthly_spend_minor' => $application['estimated_monthly_spend_minor'],
            'address' => $application['address'],
            'status' => 'submitted',
        ]);
    }
}
