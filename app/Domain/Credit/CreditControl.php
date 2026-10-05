<?php
namespace App\Domain\Credit;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\PaymentTerms;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SystemConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
final class CreditControl
{
    public function update(int $companyId, User $actor, int $limit, string $terms, string $status, string $reason, string $suspensionReason = 'manual'): Company
    {
        return DB::transaction(function () use ($companyId,$actor,$limit,$terms,$status,$reason,$suspensionReason): Company {
            $company = Company::query()->where('id',$companyId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh() ?? $actor)->authorize('manageCredit',$company);
            if ($limit < 0 || PaymentTerms::tryFrom($terms) === null || ! in_array($status,['approved','suspended'],true) || trim($reason) === '' || mb_strlen($reason)>500) {
                throw ValidationException::withMessages(['reason'=>'A valid limit, terms, status and reason are required.']);
            }
            if (! in_array($company->status,['approved','suspended'],true)) { throw new CreditRefused('company_not_approved','Only an approved or suspended trade account can be changed.'); }
            $before = $company->credit_limit_minor;
            $beforeState = $company->status.':'.$company->payment_terms;
            $company->forceFill(['credit_limit_minor'=>$limit,'payment_terms'=>$terms,'status'=>$status])->save();
            if ($status === 'suspended') {
                if (! in_array($suspensionReason,['debt','fraud','legal','manual'],true)) { throw ValidationException::withMessages(['suspension_reason'=>'Choose a suspension reason.']); }
                SystemConfiguration::query()->updateOrCreate(['config_key'=>'credit.suspension_reason','scope'=>'company','company_id'=>$companyId],
                    ['value_type'=>'text','value_text'=>$suspensionReason,'value_int'=>null,'updated_by_user_id'=>$actor->id]);
            }
            if ($before !== $limit) {
                (new AuditLogger)->record(new AuditEntry(action: AuditAction::CreditLimitChanged, actorType:'user', actorUserId:$actor->id,
                    companyId:$companyId,subjectType:'company',subjectId:$companyId,
                    before:['credit_limit_minor'=>$before],after:['credit_limit_minor'=>$limit],reason:$reason));
            }
            (new CreditAudit)->status($companyId,'company',$companyId,$beforeState,$status.':'.$terms,$actor->id,$reason);
            return $company;
        });
    }
    public function suspendOverdue(): int
    {
        $count=0;
        Company::query()->where('status','approved')->orderBy('id')->eachById(function (Company $candidate) use (&$count): void {
            $count += DB::transaction(function () use ($candidate): int {
                $company=Company::query()->where('id',$candidate->id)->lockForUpdate()->firstOrFail();
                if ($company->status !== 'approved') { return 0; }
                $days=(new CreditSettings)->integer('credit.auto_suspend_days',30,$company->id);
                $overdue=Invoice::query()->where('company_id',$company->id)->whereNotIn('status',['void','credited'])
                    ->whereRaw('total_gross_minor > paid_minor + credited_minor')->where('due_at','<=',now()->subDays($days))->exists();
                if (! $overdue) { return 0; }
                $company->forceFill(['status'=>'suspended'])->save();
                SystemConfiguration::query()->updateOrCreate(['config_key'=>'credit.suspension_reason','scope'=>'company','company_id'=>$company->id],
                    ['value_type'=>'text','value_text'=>'debt','value_int'=>null]);
                (new CreditAudit)->status($company->id,'company',$company->id,'approved','suspended',null,'automatic_overdue_debt');
                return 1;
            });
        });
        return $count;
    }
}
