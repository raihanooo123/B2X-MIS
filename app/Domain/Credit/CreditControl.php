<?php

namespace App\Domain\Credit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\PaymentTerms;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §9, §18.3 — accounts' control of a company's credit: limit, terms,
 * suspension with a reason, reinstatement; and the nightly automatic
 * suspension for debt. Every change is audited with its actor and reason.
 * Suspension never cancels existing orders or hides invoices (05.2 §9).
 */
final class CreditControl
{
    public function __construct(
        private readonly CreditSettings $settings = new CreditSettings,
        private readonly CreditAudit $audit = new CreditAudit,
    ) {}

    /**
     * @param  string  $status  `approved` (active) or `suspended`
     *
     * @throws CreditRefused
     */
    public function update(int $companyId, User $actor, int $limitMinor, string $terms, string $status, string $reason, ?string $suspensionReason = null): Company
    {
        $reason = trim($reason);
        $errors = [];
        if ($limitMinor < 0) {
            $errors['credit_limit'] = 'The credit limit cannot be negative.';
        }
        if (PaymentTerms::tryFrom($terms) === null) {
            $errors['payment_terms'] = 'Choose payment terms.';
        }
        if (! in_array($status, ['approved', 'suspended'], true)) {
            $errors['status'] = 'Choose active or suspended.';
        }
        if ($status === 'suspended' && ! in_array($suspensionReason, CreditSettings::SUSPENSION_REASONS, true)) {
            $errors['suspension_reason'] = 'Choose why the account is suspended.';
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            $errors['reason'] = 'Give a reason (up to 500 characters).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($companyId, $actor, $limitMinor, $terms, $status, $reason, $suspensionReason): Company {
            $company = Company::query()->where('id', $companyId)->lockForUpdate()->firstOrFail();
            Gate::forUser(User::query()->findOrFail($actor->id))->authorize('manageCredit', $company);
            if (! in_array($company->status, ['approved', 'suspended'], true)) {
                throw new CreditRefused('company_not_approved', 'Only an approved or suspended trade account has credit to manage.', 409);
            }

            $before = ['status' => $company->status, 'payment_terms' => $company->payment_terms, 'credit_limit_minor' => $company->credit_limit_minor];
            $after = ['status' => $status, 'payment_terms' => $terms, 'credit_limit_minor' => $limitMinor];
            $previousReason = $company->status === 'suspended' ? $this->settings->suspensionReason($company->id) : null;
            if ($before === $after && $previousReason === ($status === 'suspended' ? $suspensionReason : null)) {
                return $company;
            }

            $company->forceFill(['credit_limit_minor' => $limitMinor, 'payment_terms' => $terms, 'status' => $status])->save();
            if ($status === 'suspended' && $suspensionReason !== null) {
                $this->settings->recordSuspensionReason($company->id, $suspensionReason, $actor->id);
            }

            if ($before['credit_limit_minor'] !== $limitMinor) {
                (new AuditLogger)->record(new AuditEntry(
                    action: AuditAction::CreditLimitChanged,
                    actorType: 'user',
                    actorUserId: $actor->id,
                    companyId: $company->id,
                    subjectType: 'company',
                    subjectId: $company->id,
                    before: ['credit_limit_minor' => $before['credit_limit_minor']],
                    after: ['credit_limit_minor' => $limitMinor],
                ));
            }
            $this->audit->record($company->id, 'company', $company->id, $before, $after, $actor->id,
                $status === 'suspended' ? "{$suspensionReason}: {$reason}" : $reason);

            return $company;
        });
    }

    /**
     * Nightly: an approved company with an invoice unpaid more than
     * `credit.auto_suspend_days` (default 30) past due is suspended for
     * debt — prepayment still allowed (05.2 §18.5). Never reinstates.
     *
     * @return int companies suspended
     */
    public function suspendOverdue(): int
    {
        $candidates = CreditGate::outstanding(Invoice::query())->whereNotNull('company_id')->where('due_at', '<', now())
            ->distinct()->orderBy('company_id')->pluck('company_id');

        $suspended = 0;
        foreach ($candidates as $companyId) {
            $suspended += DB::transaction(function () use ($companyId): int {
                $company = Company::query()->where('id', $companyId)->lockForUpdate()->first();
                if ($company === null || $company->status !== 'approved') {
                    return 0;
                }
                $days = $this->settings->integer(CreditSettings::AUTO_SUSPEND_DAYS, 30, $company->id);
                $overdue = CreditGate::outstanding(Invoice::query()->where('company_id', $company->id))
                    ->where('due_at', '<=', now()->subDays($days))->exists();
                if (! $overdue) {
                    return 0;
                }

                $company->forceFill(['status' => 'suspended'])->save();
                $this->settings->recordSuspensionReason($company->id, 'debt', null);
                $this->audit->record($company->id, 'company', $company->id, ['status' => 'approved'], ['status' => 'suspended'], null, "debt: invoice over {$days} days overdue");

                return 1;
            });
        }

        return $suspended;
    }
}
