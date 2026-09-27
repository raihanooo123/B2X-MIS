<?php

namespace App\Domain\Audit;

use App\Domain\Billing\PaymentTerms;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class AuditLogger
{
    public function record(AuditEntry $entry): void
    {
        if (($entry->actorType === 'user') !== ($entry->actorUserId !== null)
            || ! in_array($entry->actorType, ['user', 'system', 'anonymous'], true)) {
            throw new InvalidArgumentException('Invalid audit actor.');
        }
        if (($entry->subjectType === null) !== ($entry->subjectId === null)) {
            throw new InvalidArgumentException('Audit subject type and id must be supplied together.');
        }

        $this->validateFields($entry->before, $entry->action->beforeFields());
        $this->validateFields($entry->after, $entry->action->afterFields());
        match ($entry->action) {
            AuditAction::SignInFailed => $this->validateFailedSignIn($entry),
            AuditAction::StaffCreated, AuditAction::StaffOnboardingRequested,
            AuditAction::StaffRoleGranted, AuditAction::StaffRoleRevoked,
            AuditAction::StaffSuspended, AuditAction::StaffReinstated,
            AuditAction::StaffTwoFactorReset,
            AuditAction::CustomerSuspended, AuditAction::CustomerReinstated => $this->validateUserAdministrationEvent($entry),
            AuditAction::ApplicationReviewStarted, AuditAction::ApplicationInfoRequested, AuditAction::ApplicationReviewResumed,
            AuditAction::ApplicationRejected, AuditAction::ApplicationApproved => $this->validateApplicationEvent($entry),
            AuditAction::CreditLimitChanged => $this->validateCreditLimitChange($entry),
        };

        DB::table('audit_log')->insert([
            'event_family' => $entry->action->family(),
            'action' => $entry->action->value,
            'actor_type' => $entry->actorType,
            'actor_user_id' => $entry->actorUserId,
            'acting_for_company_id' => $entry->actingForCompanyId,
            'company_id' => $entry->companyId,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'before' => $entry->before === [] ? null : json_encode($entry->before, JSON_THROW_ON_ERROR),
            'after' => $entry->after === [] ? null : json_encode($entry->after, JSON_THROW_ON_ERROR),
            'reason' => $entry->reason,
            'ip' => $entry->ip,
            'user_agent' => $entry->userAgent,
        ]);
    }

    public function failedSignIn(string $identifier, ?string $ip, ?string $userAgent): void
    {
        $identifier = mb_strtolower(trim($identifier));
        if ($identifier === '') {
            throw new InvalidArgumentException('A failed sign-in requires an identifier.');
        }

        $key = (string) config('audit.identifier_key');
        $version = (string) config('audit.identifier_key_version');
        if (strlen($key) < 32 || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $version) !== 1) {
            throw new RuntimeException('Audit identifier key and version must be configured.');
        }

        $this->record(new AuditEntry(
            action: AuditAction::SignInFailed,
            actorType: 'anonymous',
            after: [
                'identifier_fingerprint' => hash_hmac('sha256', $identifier, $key),
                'key_version' => $version,
            ],
            ip: $ip,
            userAgent: $userAgent,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     */
    private function validateFields(array $payload, array $allowed): void
    {
        foreach ($payload as $key => $value) {
            if (! in_array($key, $allowed, true)
                || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new InvalidArgumentException('Unsupported audit payload field.');
            }
        }
    }

    /**
     * 05.2 §4 transitions of one application, by a staff user. Status
     * only — never the internal reason, the information request or any
     * applicant detail. An approval also names the company it created,
     * which must match the entry's company, and the terms granted.
     */
    private function validateApplicationEvent(AuditEntry $entry): void
    {
        $from = $entry->before['status'] ?? null;
        $to = $entry->after['status'] ?? null;
        $after = $entry->after;

        $valid = count($entry->before) === 1 && match ($entry->action) {
            AuditAction::ApplicationReviewStarted => $from === 'submitted' && $after === ['status' => 'in_review'],
            AuditAction::ApplicationInfoRequested => $from === 'in_review' && $after === ['status' => 'info_requested'],
            AuditAction::ApplicationReviewResumed => $from === 'info_requested' && $after === ['status' => 'in_review'],
            AuditAction::ApplicationRejected => $from === 'in_review' && $to === 'rejected' && count($after) === 2
                && is_bool($after['remediable'] ?? null),
            AuditAction::ApplicationApproved => $from === 'in_review' && $to === 'approved' && count($after) === 6
                && is_int($after['company_id'] ?? null) && $after['company_id'] === $entry->companyId
                && is_int($after['owner_user_id'] ?? null)
                && is_int($after['price_tier_id'] ?? null)
                && is_string($after['payment_terms'] ?? null) && PaymentTerms::tryFrom($after['payment_terms']) !== null
                && is_int($after['credit_limit_minor'] ?? null) && $after['credit_limit_minor'] >= 0,
            default => throw new InvalidArgumentException('Unsupported application audit action.'),
        };

        if (! $valid || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'b2b_application' || $entry->subjectId === null
            || $entry->reason !== null || $entry->actingForCompanyId !== null
            || ($entry->action !== AuditAction::ApplicationApproved && $entry->companyId !== null)) {
            throw new InvalidArgumentException('Invalid application audit entry.');
        }
    }

    /**
     * 07 §6.5: a company's credit limit changed, in whole pence
     * (invariant 1). The subject is the company.
     */
    private function validateCreditLimitChange(AuditEntry $entry): void
    {
        $before = $entry->before['credit_limit_minor'] ?? null;
        $after = $entry->after['credit_limit_minor'] ?? null;

        if (! is_int($before) || ! is_int($after) || $before < 0 || $after < 0 || $before === $after
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'company' || $entry->subjectId === null
            || $entry->companyId !== $entry->subjectId
            || $entry->reason !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid credit limit audit entry.');
        }
    }

    private function validateFailedSignIn(AuditEntry $entry): void
    {
        if ($entry->actorType !== 'anonymous' || $entry->actorUserId !== null
            || $entry->before !== [] || $entry->reason !== null
            || $entry->subjectType !== null || $entry->subjectId !== null
            || count($entry->after) !== 2
            || ! is_string($entry->after['identifier_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $entry->after['identifier_fingerprint']) !== 1
            || ! is_string($entry->after['key_version'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $entry->after['key_version']) !== 1) {
            throw new InvalidArgumentException('Invalid failed sign-in audit entry.');
        }
    }

    /**
     * An administrator acting on one user account — staff or customer. The
     * subject is the user, never a company: customer suspension is per user
     * (05.13 §4.2), so no company is recorded.
     */
    private function validateUserAdministrationEvent(AuditEntry $entry): void
    {
        $roles = ['admin', 'accounts', 'purchasing', 'rep', 'warehouse', 'sales_manager'];
        $valid = match ($entry->action) {
            AuditAction::StaffCreated => $entry->before === [] && $entry->after === ['status' => 'pending'],
            AuditAction::StaffOnboardingRequested => $entry->before === [] && $entry->after === ['channel' => 'email'],
            AuditAction::StaffRoleGranted => $entry->before === [] && count($entry->after) === 1
                && in_array($entry->after['role'] ?? null, $roles, true),
            AuditAction::StaffRoleRevoked => $entry->after === [] && count($entry->before) === 2
                && in_array($entry->before['role'] ?? null, $roles, true)
                && array_key_exists('granted_by_user_id', $entry->before)
                && ($entry->before['granted_by_user_id'] === null || is_int($entry->before['granted_by_user_id'])),
            AuditAction::StaffSuspended, AuditAction::CustomerSuspended => $entry->before === ['status' => 'active'] && $entry->after === ['status' => 'suspended'],
            AuditAction::StaffReinstated, AuditAction::CustomerReinstated => $entry->before === ['status' => 'suspended'] && $entry->after === ['status' => 'active'],
            AuditAction::StaffTwoFactorReset => $entry->before === ['two_factor_enabled' => true] && $entry->after === ['two_factor_enabled' => false],
            default => throw new InvalidArgumentException('Unsupported user administration audit action.'),
        };

        if (! $valid || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'user' || $entry->subjectId === null
            || $entry->reason !== null
            || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid user administration audit entry.');
        }
    }
}
