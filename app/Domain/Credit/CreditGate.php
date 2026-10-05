<?php
namespace App\Domain\Credit;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\User;
final class CreditGate
{
    public function __construct(private readonly CreditSettings $settings = new CreditSettings) {}
    public function assertCanOrder(Company $company, ?int $buyerId, string $method): CompanyUser
    {
        $buyer = $buyerId === null ? null : User::query()->find($buyerId);
        $member = $buyer === null ? null : CompanyUser::query()->where('company_id', $company->id)->where('user_id', $buyerId)->first();
        if ($buyer === null || $buyer->status !== 'active' || $member === null || ! in_array($member->role, ['owner','buyer','approver'], true)) {
            throw new CreditRefused('not_permitted_to_order', 'An active buying member of this company must place the order.', 403);
        }
        if (! in_array($company->status, ['approved','suspended'], true)) {
            throw new CreditRefused('company_not_approved', 'This company cannot place trade orders.');
        }
        if ($company->status === 'suspended' && ($method === 'on_account' || $this->settings->suspensionReason($company->id) !== 'debt')) {
            throw new CreditRefused('company_suspended', $method === 'on_account' ? 'This account is suspended. Contact accounts or use an allowed prepayment method.' : 'This account is suspended for all sales. Contact accounts.');
        }
        if ($method === 'on_account') {
            if ($company->payment_terms === 'prepay') {
                throw new CreditRefused('on_account_unavailable', 'This account is on prepayment terms.');
            }
            if ($this->overdue($company->id)) {
                throw new CreditRefused('overdue_debt', 'An overdue invoice blocks on-account ordering. Pay the invoice or use prepayment.');
            }
        }
        return $member;
    }
    public function overdue(int $companyId): bool
    {
        $boundary = now()->subDays($this->settings->integer('credit.overdue_grace_days', 0, $companyId));
        return Invoice::query()->where('company_id', $companyId)->whereNotIn('status', ['void','credited'])
            ->where('due_at', '<', $boundary)->whereRaw('total_gross_minor > paid_minor + credited_minor')->exists();
    }
    public function needsBuyerApproval(CompanyUser $member, int $grossMinor): bool
    { return $member->requires_approval || ($member->order_limit_minor !== null && $grossMinor > $member->order_limit_minor); }
    public function available(Company $company): int
    { return $company->credit_limit_minor - $company->credit_used_minor - $company->credit_held_minor; }
}
