<?php

namespace App\Http\Support;

use App\Domain\Identity\CompanyMemberships;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Which company a signed-in user is acting for this session (05.13 §6.3).
 *
 * One active company → that one, no choice needed. Several → the one the
 * user chose at sign-in, held in the session under SESSION_KEY and never
 * persisted; the next sign-in asks again. The stored value is re-checked
 * against the user's current memberships on every read, so a company the
 * user has since been removed from is never acted for.
 */
final class ActingCompany
{
    public const SESSION_KEY = 'auth.company_id';

    /** The chosen company id if it is still valid, else null. */
    public static function chosenId(Session $session, User $user): ?int
    {
        $chosen = $session->get(self::SESSION_KEY);
        if (! is_int($chosen)) {
            return null;
        }

        return in_array($chosen, CompanyMemberships::ids($user), true) ? $chosen : null;
    }

    public static function needsChoice(Session $session, User $user): bool
    {
        return count(CompanyMemberships::ids($user)) > 1 && self::chosenId($session, $user) === null;
    }

    /** The company being acted for, or null (no company, or a choice still due). */
    public static function current(Session $session, User $user): ?Company
    {
        $companies = CompanyMemberships::for($user);
        if ($companies->count() === 1) {
            return $companies->first();
        }

        $chosen = self::chosenId($session, $user);

        return $chosen === null ? null : $companies->firstWhere('id', $chosen);
    }

    public static function choose(Session $session, Company $company): void
    {
        $session->put(self::SESSION_KEY, (int) $company->id);
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
