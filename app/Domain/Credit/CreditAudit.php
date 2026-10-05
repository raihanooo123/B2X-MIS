<?php

namespace App\Domain\Credit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\OrderApprovalRequest;

/**
 * 05.2 §18: every credit decision is audit logged (`credit.operation`,
 * family `credit_limit`) with its actor — `system` for the reaper and the
 * nightly suspension — and the reason given.
 */
final class CreditAudit
{
    public function decision(OrderApprovalRequest $request, ?int $actorUserId): void
    {
        $this->record($request->company_id, 'order_approval_request', $request->id,
            ['status' => ApprovalStatus::Pending->value, 'approval_kind' => $request->approval_kind],
            ['status' => $request->status, 'approval_kind' => $request->approval_kind],
            $actorUserId, $request->decision_reason ?? $request->status);
    }

    public function orderStatus(int $companyId, int $orderId, string $before, string $after, ?int $actorUserId, string $reason): void
    {
        $this->record($companyId, 'order', $orderId, ['status' => $before], ['status' => $after], $actorUserId, $reason);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(int $companyId, string $subjectType, int $subjectId, array $before, array $after, ?int $actorUserId, string $reason): void
    {
        (new AuditLogger)->record(new AuditEntry(
            action: AuditAction::CreditOperation,
            actorType: $actorUserId === null ? 'system' : 'user',
            actorUserId: $actorUserId,
            companyId: $companyId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            before: $before,
            after: $after,
            reason: $reason,
        ));
    }
}
