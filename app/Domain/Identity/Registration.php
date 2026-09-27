<?php

namespace App\Domain\Identity;

use App\Domain\Accounts\AcceptedTerms;
use App\Domain\Accounts\LegalForm;
use App\Domain\Accounts\TermsAcceptanceSource;
use App\Domain\Accounts\TermsKind;
use App\Models\B2bApplication;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
    public const TERMS_CHANGED = 'Our terms of trade have been updated since you opened this page. Please read the new version and accept it to continue.';

    public static function emailTaken(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /**
     * 05.2 §5.2: "an open application on the same contact_email → block".
     * The oldest open one, served by `b2b_applications_email_idx`. On the
     * public form the caller must not reveal it on screen: the address is
     * told by email instead (05.13 §5.1, no enumeration).
     */
    public static function openApplicationForEmail(string $email): ?B2bApplication
    {
        return B2bApplication::query()
            ->where('contact_email', $email)
            ->whereIn('status', B2bApplication::OPEN_STATUSES)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->first(['id']);
    }

    /**
     * 05.13 §7, 02 §25.2: the latest rejection on this address, when it is
     * still cooling — null when nothing is in the way (no rejection, a
     * remediable one, or the date has passed). For a visitor who is not
     * signed in, keyed on the contact email. The caller must not reveal the
     * result on screen: the details go to the address by email (§5.1, no
     * enumeration).
     */
    public static function coolingRejectionForEmail(string $email): ?B2bApplication
    {
        return self::coolingRejection(B2bApplication::query()->where('contact_email', $email));
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
     * @param  array{company_name: string, legal_form: LegalForm, registration_number: ?string, vat_number: ?string, business_type: BusinessType, estimated_monthly_spend_minor: ?int, address: array<string, string|null>}  $application
     * @return array{0: User, 1: B2bApplication}
     */
    public static function tradeApplicant(array $person, array $application, AcceptedTerms $terms): array
    {
        return DB::transaction(function () use ($person, $application, $terms) {
            $user = User::query()->create([
                'email' => $person['email'],
                'password_hash' => Hash::make($person['password']),
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'phone' => $person['phone'],
                'status' => 'active',
            ]);

            $filed = self::fileApplication($user, $person['phone'], $application, $terms);

            return [$user, $filed];
        });
    }

    /**
     * Files an application for a user, with the terms they accepted
     * (02 §25.1) — also the entry point for the signed-in application form
     * (05.13 §5.1) when it is built.
     *
     * The user's row is locked first, so two applications from one user
     * serialise: the open-application check and the re-application gate
     * (05.13 §7) are read after any competing filing commits.
     *
     * @param  array{company_name: string, legal_form: LegalForm, registration_number: ?string, vat_number: ?string, business_type: BusinessType, estimated_monthly_spend_minor: ?int, address: array<string, string|null>}  $application
     */
    public static function fileApplication(User $user, ?string $phone, array $application, AcceptedTerms $terms): B2bApplication
    {
        return DB::transaction(function () use ($user, $phone, $application, $terms) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (TermsVersion::current(TermsKind::Trade)?->id !== $terms->termsVersionId) {
                throw ValidationException::withMessages(['terms_version_id' => self::TERMS_CHANGED]);
            }
            // Signed in, the applicant is told directly.
            if (B2bApplication::query()->where('applicant_user_id', $locked->id)->whereIn('status', B2bApplication::OPEN_STATUSES)->exists()) {
                throw ValidationException::withMessages(['application' => 'You already have an application in progress. We will be in touch.']);
            }
            // Signed in, the applicant is told the date directly.
            $cooling = self::coolingRejection(B2bApplication::query()->where('applicant_user_id', $locked->id));
            if ($cooling?->reapply_after !== null) {
                throw ValidationException::withMessages(['application' => self::reapplyMessage($cooling->reapply_after)]);
            }

            $filed = B2bApplication::query()->create([
                'applicant_user_id' => $locked->id,
                'company_name' => $application['company_name'],
                'legal_form' => $application['legal_form']->value,
                'registration_number' => $application['registration_number'],
                'vat_number' => $application['vat_number'],
                'contact_name' => trim("{$locked->first_name} {$locked->last_name}"),
                'contact_email' => $locked->email,
                'contact_phone' => $phone,
                'business_type' => $application['business_type']->value,
                'estimated_monthly_spend_minor' => $application['estimated_monthly_spend_minor'],
                'address' => $application['address'],
                'status' => 'submitted',
            ]);

            TermsAcceptance::query()->create([
                'terms_version_id' => $terms->termsVersionId,
                'user_id' => $locked->id,
                'b2b_application_id' => $filed->id,
                'source' => TermsAcceptanceSource::TradeApplication->value,
                'ip' => $terms->ip,
                'user_agent' => $terms->userAgent,
            ]);

            return $filed;
        });
    }

    public static function reapplyMessage(CarbonInterface $until): string
    {
        return 'You can apply for a trade account again from '.$until->copy()->timezone('Europe/London')->format('j F Y').'. You can still buy at our standard prices meanwhile.';
    }

    /**
     * The latest rejection, if its `reapply_after` is still in the future.
     * `b2b_applications_reapply_idx` serves the user-keyed form (02 §25.2).
     *
     * @param  Builder<B2bApplication>  $applications
     */
    private static function coolingRejection(Builder $applications): ?B2bApplication
    {
        $latest = $applications->where('status', 'rejected')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first(['id', 'reapply_after']);

        return $latest?->reapply_after !== null && $latest->reapply_after->isFuture() ? $latest : null;
    }
}
