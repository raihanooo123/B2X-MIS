<?php

namespace App\Domain\Credit;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 05.2 §18.1 — the gates every trade order path runs (checkout, approval
 * decisions, later quote conversion and rep orders). Called under the
 * `companies` lock so membership, company state and limits are the live
 * ones, not what the page showed: hiding a button is not a control.
 *
 *   - Only an active owner/buyer/approver of the company orders.
 *   - approved, or suspended for ordinary debt (prepay only), may order;
 *     any other suspension reason blocks every sale.
 *   - On account needs credit terms and no invoice overdue beyond
 *     `credit.overdue_grace_days` (default 0).
 */
final class CreditGate
{
    /** Invoices that still carry debt (invoices_credit_unpaid_idx). */
    public const UNPAID_STATUSES = ['issued', 'part_paid', 'overdue'];

    public function __construct(private readonly CreditSettings $settings = new CreditSettings) {}

    /**
     * @throws CreditRefused
     */
    public function assertCanOrder(Company $company, ?int $buyerId, string $method): CompanyUser
    {
        $buyer = $buyerId === null ? null : User::query()->find($buyerId);
        $member = $buyer === null ? null : CompanyUser::query()
            ->where('company_id', $company->id)->where('user_id', $buyer->id)->first();
        if ($buyer === null || $buyer->status !== 'active' || $member === null || ! in_array($member->role, ['owner', 'buyer', 'approver'], true)) {
            throw new CreditRefused('not_permitted_to_order', 'Only an active buying member of this company can place this order.', 403);
        }

        $refusal = $this->companyRefusal($company, $method);
        if ($refusal !== null) {
            throw $refusal;
        }

        return $member;
    }

    /** Why this company cannot order by this method now, or null. */
    public function companyRefusal(Company $company, string $method): ?CreditRefused
    {
        if (! in_array($company->status, ['approved', 'suspended'], true)) {
            return new CreditRefused('company_not_approved', 'This trade account cannot place orders. Contact accounts.');
        }

        if ($company->status === 'suspended') {
            if ($method === 'on_account') {
                return new CreditRefused('credit_account_suspended', 'This account is suspended for on-account orders. Pay by card or bank transfer, or contact accounts.');
            }
            if ($this->settings->suspensionReason($company->id) !== 'debt') {
                return new CreditRefused('credit_account_suspended', 'This account is suspended. Contact accounts before ordering.');
            }
        }

        if ($method !== 'on_account') {
            return null;
        }
        if ($company->payment_terms === 'prepay') {
            return new CreditRefused('payment_method_not_available', 'This account pays in advance. Pay by card or bank transfer.');
        }
        if ($this->overdue($company->id)) {
            return new CreditRefused('overdue_debt', 'An overdue invoice blocks on-account ordering. Pay it, or pay for this order by card or bank transfer.');
        }

        return null;
    }

    /** Any trade invoice unpaid past its due instant plus the grace days. */
    public function overdue(int $companyId): bool
    {
        return $this->overdueInvoices($companyId)->exists();
    }

    /** @return Builder<Invoice> */
    public function overdueInvoices(int $companyId): Builder
    {
        $boundary = now()->subDays($this->settings->integer(CreditSettings::GRACE_DAYS, 0, $companyId));

        return self::outstanding(Invoice::query()->where('company_id', $companyId))
            ->where('due_at', '<', $boundary);
    }

    /**
     * Outstanding = gross − paid − credited > 0, on a document still
     * carrying debt (05.2 §18.1).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function outstanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::UNPAID_STATUSES)
            ->whereRaw('total_gross_minor > paid_minor + credited_minor');
    }

    /**
     * Full order gross including VAT and carriage, before any balance is
     * applied (05.2 §18.1), against the buyer's nullable per-order limit.
     */
    public function needsBuyerApproval(CompanyUser $member, int $grossMinor): bool
    {
        return $member->requires_approval
            || ($member->order_limit_minor !== null && $grossMinor > $member->order_limit_minor);
    }

    /** Limit − used − held. Can be negative after a limit reduction (05.2 §18.1). */
    public function available(Company $company): int
    {
        return $company->credit_limit_minor - $company->credit_used_minor - $company->credit_held_minor;
    }
}
