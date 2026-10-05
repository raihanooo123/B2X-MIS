<?php

namespace App\Domain\Collection;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PayAtCollectionEligibility
{
    public function __construct(private readonly CollectionSettings $settings = new CollectionSettings) {}

    public function reason(?int $userId, ?int $companyId, int $locationId, int $grossMinor): ?string
    {
        if (! $this->settings->enabled($locationId)) {
            return 'Cash at collection is not enabled at this location.';
        }
        if ($userId === null) {
            return 'Sign in to pay cash at collection.';
        }
        $user = User::query()->where('id', $userId)->first();
        if ($user === null || $user->status !== 'active' || $user->email_verified_at === null) {
            return 'Verify your email before choosing cash at collection.';
        }
        if ($grossMinor > $this->settings->integer('collection.pay_at_collection.max_order_gross_minor', 30000, $locationId)) {
            return 'This order is above the cash-at-collection limit.';
        }
        if ($companyId !== null && Company::query()->where('id', $companyId)->where('status', '<>', 'approved')->exists()) {
            return 'Cash at collection is unavailable for this trade account.';
        }
        $suspended = DB::table('pay_at_collection_suspensions')->whereNull('lifted_at')
            ->when($companyId !== null,
                fn ($query) => $query->where('company_id', $companyId),
                fn ($query) => $query->where('user_id', $userId))
            ->exists();

        return $suspended ? 'Cash at collection is suspended for this account.' : null;
    }
}
