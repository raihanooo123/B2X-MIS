<?php

namespace App\Domain\Collection;

use App\Filament\Support\MoneyFormatter;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §7A.3 — who may pay cash at collection, and up to how much. Checked
 * in the checkout preview (shown as a reason) and again inside the
 * place-order transaction, after the slot lock.
 *
 *   enabled     `collection.pay_at_collection.enabled` for the slot's location
 *   signed_in   a public customer or a trade user — never a guest
 *   verified    a verified email (05.13 §11), trade and public alike
 *   standing    a trade account that is approved (treated as prepay, no credit hold)
 *   suspended   no active `pay_at_collection_suspensions` row for the user
 *               (public) or the company (trade, Q-C2)
 *   limit       order `total_gross_minor` ≤ the limit — gross, as cash is paid gross
 */
final class PayAtCollectionEligibility
{
    public function __construct(private readonly CollectionSettings $settings = new CollectionSettings) {}

    /** @throws PayAtCollectionNotEligible */
    public function assert(?int $userId, ?int $companyId, int $locationId, int $totalGrossMinor): void
    {
        $refusal = $this->refusal($userId, $companyId, $locationId, $totalGrossMinor);
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    public function refusal(?int $userId, ?int $companyId, int $locationId, int $totalGrossMinor): ?PayAtCollectionNotEligible
    {
        if (! $this->settings->cashEnabled($locationId)) {
            return new PayAtCollectionNotEligible('enabled', 'Paying cash at collection is not available at this location.');
        }
        if ($userId === null) {
            return new PayAtCollectionNotEligible('signed_in', 'Sign in to pay cash at collection. Guest orders are paid by card.');
        }
        $user = User::query()->where('id', $userId)->first(['id', 'status', 'email_verified_at']);
        if ($user === null || $user->status !== 'active') {
            return new PayAtCollectionNotEligible('signed_in', 'Sign in to pay cash at collection.');
        }
        if (! $user->hasVerifiedEmail()) {
            return new PayAtCollectionNotEligible('verified', 'Verify your email address to pay cash at collection.');
        }
        if ($companyId !== null && Company::query()->where('id', $companyId)->value('status') !== 'approved') {
            return new PayAtCollectionNotEligible('standing', 'Paying cash at collection is not available for this account. Please pay by card.');
        }
        if ($this->suspended($userId, $companyId)) {
            return new PayAtCollectionNotEligible('suspended', 'Paying cash at collection is not available for this account after missed collections. Please pay by card.');
        }
        $limit = $this->settings->cashLimitGrossMinor($locationId);
        if ($totalGrossMinor > $limit) {
            return new PayAtCollectionNotEligible('limit', 'Paying cash at collection is available for orders up to '.(string) MoneyFormatter::minor($limit).' inc VAT. Please pay by card.');
        }

        return null;
    }

    /** `pay_at_collection_suspensions_{user,company}_active_uq` answer this. */
    public function suspended(?int $userId, ?int $companyId): bool
    {
        return DB::table('pay_at_collection_suspensions')->whereNull('lifted_at')
            ->when($companyId !== null,
                fn ($q) => $q->where('company_id', $companyId),
                fn ($q) => $q->where('user_id', $userId))
            ->exists();
    }
}
