<?php
namespace App\Domain\Credit;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\OrderApprovalRequest;
final class CreditAudit
{
    public function decision(OrderApprovalRequest $request, ?int $actorId): void
    {
        $this->status($request->company_id, 'order', $request->order_id, 'pending', $request->status,
            $actorId, $request->decision_reason ?? $request->approval_kind);
    }
    public function status(int $companyId, string $type, int $id, string $before, string $after, ?int $actorId, string $reason): void
    {
        (new AuditLogger)->record(new AuditEntry(action: AuditAction::CreditOperation,
            actorType: $actorId === null ? 'system' : 'user', actorUserId: $actorId,
            companyId: $companyId, subjectType: $type, subjectId: $id,
            before: ['status' => $before], after: ['status' => $after], reason: $reason));
    }
}
