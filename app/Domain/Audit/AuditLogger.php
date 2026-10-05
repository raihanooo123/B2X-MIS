<?php

namespace App\Domain\Audit;

use App\Domain\Accounts\RejectionCategory;
use App\Domain\Accounts\TermsKind;
use App\Domain\Accounts\VerificationWarning;
use App\Domain\Billing\PaymentTerms;
use App\Domain\Cms\PageKey;
use App\Domain\Identity\CompanyMemberRole;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class AuditLogger
{
    /**
     * A staff action (`actor_type = user`) with no IP of its own records the
     * current request's IP and user agent (07 §6.5, AuditContext). Entries
     * that carry their own — a failed sign-in — are left as they are.
     */
    public function record(AuditEntry $entry): void
    {
        if ($entry->actorType === 'user' && $entry->ip === null && $entry->userAgent === null) {
            $context = app(AuditContext::class);
            $entry = $entry->withClient($context->ip, $context->userAgent);
        }

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
            AuditAction::CompanyInvited, AuditAction::CompanyInvitationRevoked, AuditAction::CompanyInvitationAccepted,
            AuditAction::CompanyMemberChanged, AuditAction::CompanyMemberRemoved => $this->validateCompanyAdministration($entry),
            AuditAction::SignInFailed => $this->validateFailedSignIn($entry),
            AuditAction::StaffCreated, AuditAction::StaffOnboardingRequested,
            AuditAction::StaffRoleGranted, AuditAction::StaffRoleRevoked,
            AuditAction::StaffSuspended, AuditAction::StaffReinstated,
            AuditAction::StaffTwoFactorReset,
            AuditAction::CustomerSuspended, AuditAction::CustomerReinstated => $this->validateUserAdministrationEvent($entry),
            AuditAction::ApplicationReviewStarted, AuditAction::ApplicationInfoRequested, AuditAction::ApplicationReviewResumed,
            AuditAction::ApplicationRejected, AuditAction::ApplicationApproved,
            AuditAction::ApplicationVerificationRequested => $this->validateApplicationEvent($entry),
            AuditAction::CreditOperation => $this->validateCreditOperation($entry),
            AuditAction::CreditLimitChanged => $this->validateCreditLimitChange($entry),
            AuditAction::TermsVersionPublished => $this->validateTermsVersionPublished($entry),
            AuditAction::PagePublished => $this->validatePagePublished($entry),
            AuditAction::StorefrontSettingsChanged => $this->validateStorefrontSettings($entry),
            AuditAction::OrderClaimed => $this->validateOrderClaimed($entry),
            AuditAction::RmaProofRejected => $this->validateRmaProofRejected($entry),
            AuditAction::RmaAdvanceReplacement => $this->validateRmaAdvanceReplacement($entry),
            AuditAction::OrderCancelBelowBreak => $this->validateOrderCancelBelowBreak($entry),
            AuditAction::PaymentCashRecorded, AuditAction::PaymentCashVoided => $this->validateCashPayment($entry),
            AuditAction::PayAtCollectionSuspended, AuditAction::PayAtCollectionReinstated => $this->validatePayAtCollectionSuspension($entry),
            AuditAction::LockedOut => $this->validateLockout($entry),
            AuditAction::SignedIn, AuditAction::SignedOut, AuditAction::SessionExpired,
            AuditAction::PasswordResetRequested, AuditAction::PasswordResetCompleted,
            AuditAction::TwoFactorEnabled, AuditAction::TwoFactorDisabled,
            AuditAction::RecoveryCodesRegenerated, AuditAction::RecoveryCodeUsed => $this->validateOwnAuthEvent($entry),
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
        $this->record(new AuditEntry(
            action: AuditAction::SignInFailed,
            actorType: 'anonymous',
            after: $this->fingerprint($identifier),
            ip: $ip,
            userAgent: $userAgent,
        ));
    }

    /**
     * 05.13 §6.2, §15: an identifier has just been locked out. Keyed
     * fingerprint only, as for a failed sign-in (02 §15.3 ⚑2).
     */
    public function lockedOut(string $identifier, int $lockedSeconds, ?string $ip, ?string $userAgent): void
    {
        $this->record(new AuditEntry(
            action: AuditAction::LockedOut,
            actorType: 'anonymous',
            after: [...$this->fingerprint($identifier), 'locked_seconds' => $lockedSeconds],
            ip: $ip,
            userAgent: $userAgent,
        ));
    }

    /**
     * 05.13 §15: a person's own authentication event, recorded with them as
     * both actor and subject (a reset request is anonymous: the requester
     * is not signed in). IP and user agent come from the request.
     *
     * @param  array<string, int|string>  $after
     */
    public function ownAuthEvent(AuditAction $action, int $userId, array $after = [], bool $anonymous = false): void
    {
        $context = app(AuditContext::class);
        $this->record(new AuditEntry(
            action: $action,
            actorType: $anonymous ? 'anonymous' : 'user',
            actorUserId: $anonymous ? null : $userId,
            subjectType: 'user',
            subjectId: $userId,
            after: $after,
            ip: $context->ip,
            userAgent: $context->userAgent,
        ));
    }

    private function validateCreditOperation(AuditEntry $entry): void
    {
        if ($entry->companyId === null || $entry->subjectId === null
            || ! in_array($entry->subjectType, ['company','order','account_credit_payout'], true)
            || ! is_string($entry->before['status'] ?? null) || ! is_string($entry->after['status'] ?? null)
            || $entry->reason === null || trim($entry->reason) === '' || mb_strlen($entry->reason) > 500
            || ! in_array($entry->actorType, ['user','system'], true)) {
            throw new InvalidArgumentException('Invalid credit operation audit.');
        }
    }

    /** @return array{identifier_fingerprint: string, key_version: string} */
    private function fingerprint(string $identifier): array
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

        return ['identifier_fingerprint' => hash_hmac('sha256', $identifier, $key), 'key_version' => $version];
    }

    private function validateLockout(AuditEntry $entry): void
    {
        $seconds = $entry->after['locked_seconds'] ?? null;
        if ($entry->actorType !== 'anonymous' || $entry->subjectType !== null || $entry->reason !== null
            || $entry->companyId !== null || count($entry->after) !== 3
            || preg_match('/^[a-f0-9]{64}$/', (string) ($entry->after['identifier_fingerprint'] ?? '')) !== 1
            || ! is_string($entry->after['key_version'] ?? null)
            || ! is_int($seconds) || $seconds < 1) {
            throw new InvalidArgumentException('Invalid lockout audit entry.');
        }
    }

    private function validateOwnAuthEvent(AuditEntry $entry): void
    {
        $anonymous = $entry->action === AuditAction::PasswordResetRequested;
        $after = $entry->after;
        $afterValid = match ($entry->action) {
            AuditAction::SignedIn => count($after) === 1 && in_array($after['method'] ?? null, ['password', 'two_factor', 'recovery_code', 'invitation'], true),
            AuditAction::SessionExpired => count($after) === 1 && in_array($after['reason'] ?? null, ['idle', 'absolute', 'account_inactive'], true),
            AuditAction::RecoveryCodeUsed => count($after) === 1 && is_int($after['remaining'] ?? null) && $after['remaining'] >= 0,
            default => $after === [],
        };

        if (! $afterValid || $entry->before !== [] || $entry->reason !== null
            || $entry->companyId !== null || $entry->actingForCompanyId !== null
            || $entry->subjectType !== 'user' || $entry->subjectId === null
            || ($anonymous ? $entry->actorType !== 'anonymous' : ($entry->actorType !== 'user' || $entry->actorUserId !== $entry->subjectId))) {
            throw new InvalidArgumentException('Invalid authentication audit entry.');
        }
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

        if ($entry->action === AuditAction::ApplicationVerificationRequested) {
            $this->validateVerificationRequested($entry);

            return;
        }

        $valid = count($entry->before) === 1 && match ($entry->action) {
            AuditAction::ApplicationReviewStarted => $from === 'submitted' && $after === ['status' => 'in_review'],
            AuditAction::ApplicationInfoRequested => $from === 'in_review' && $after === ['status' => 'info_requested'],
            AuditAction::ApplicationReviewResumed => $from === 'info_requested' && $after === ['status' => 'in_review'],
            AuditAction::ApplicationRejected => $from === 'in_review' && $to === 'rejected' && count($after) === 3
                && is_bool($after['remediable'] ?? null)
                && is_string($after['rejection_category'] ?? null) && RejectionCategory::tryFrom($after['rejection_category']) !== null,
            AuditAction::ApplicationApproved => $from === 'in_review' && $to === 'approved' && count($after) === 8
                && $this->validWarnings($after['verification_warnings'] ?? null, $after['verification_acknowledged'] ?? null)
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
     * 02 §25.9: the warning codes are VerificationWarning values, sorted,
     * unique and comma-separated ('' for none), and acknowledgement is true
     * exactly when there are warnings.
     */
    private function validWarnings(mixed $codes, mixed $acknowledged): bool
    {
        if (! is_string($codes) || ! is_bool($acknowledged) || $acknowledged !== ($codes !== '')) {
            return false;
        }
        if ($codes === '') {
            return true;
        }

        $list = explode(',', $codes);
        $sorted = $list;
        sort($sorted);

        return $list === $sorted && count(array_unique($list)) === count($list)
            && array_filter($list, fn (string $code) => VerificationWarning::tryFrom($code) === null) === [];
    }

    /**
     * 02 §25.4: a reviewer re-ran the checks. `checks` names what was run —
     * `companies_house`, `vat` or both, sorted — and nothing else.
     */
    private function validateVerificationRequested(AuditEntry $entry): void
    {
        $checks = $entry->after['checks'] ?? null;

        if ($entry->before !== [] || count($entry->after) !== 1
            || ! in_array($checks, ['companies_house', 'vat', 'companies_house,vat'], true)
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'b2b_application' || $entry->subjectId === null
            || $entry->reason !== null || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid verification request audit entry.');
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

    /**
     * 02 §25.1, §25.9: a terms version published by an administrator. The
     * text itself is never copied; the row holds it and its SHA-256.
     */
    private function validateTermsVersionPublished(AuditEntry $entry): void
    {
        $after = $entry->after;
        $effective = $after['effective_from'] ?? null;

        if ($entry->before !== [] || count($after) !== 4
            || ! is_string($after['kind'] ?? null) || TermsKind::tryFrom($after['kind']) === null
            || ! is_string($after['version'] ?? null) || preg_match('/^[0-9A-Za-z._-]{1,32}$/', $after['version']) !== 1
            || ! is_string($effective) || \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $effective) === false
            || ! is_string($after['body_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $after['body_sha256']) !== 1
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'terms_version' || $entry->subjectId === null
            || $entry->reason !== null || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid terms version audit entry.');
        }
    }

    /**
     * 05.11 §2.4: by an administrator, about the page; the key, version
     * number, effective time and SHA-256 — never the text.
     */
    private function validatePagePublished(AuditEntry $entry): void
    {
        $after = $entry->after;
        $effective = $after['effective_from'] ?? null;

        if ($entry->before !== [] || count($after) !== 4
            || ! is_string($after['page_key'] ?? null) || PageKey::tryFrom($after['page_key']) === null
            || ! is_int($after['version_no'] ?? null) || $after['version_no'] < 1
            || ! is_string($effective) || \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $effective) === false
            || ! is_string($after['body_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $after['body_sha256']) !== 1
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'cms_page' || $entry->subjectId === null
            || $entry->reason !== null || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid page published audit entry.');
        }
    }

    /**
     * 05.15 §6.3: by the claimant, about the order; `user_id` from NULL to
     * the claimant. Nothing else — never the guest email.
     */
    private function validateOrderClaimed(AuditEntry $entry): void
    {
        if ($entry->before !== ['user_id' => null] || $entry->after !== ['user_id' => $entry->actorUserId]
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'order' || $entry->subjectId === null
            || $entry->reason !== null || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid order claim audit entry.');
        }
    }

    /**
     * 05.4 §13.5: by a staff user, about the return; `goods_sent_at` from the
     * upload time (ISO 8601) to null, the last proof file the rejection
     * covers, and the reason the customer was given. The files themselves
     * are untouched.
     */
    private function validateRmaProofRejected(AuditEntry $entry): void
    {
        $sentAt = $entry->before['goods_sent_at'] ?? null;
        $lastFile = $entry->before['last_proof_attachment_id'] ?? null;

        if (array_keys($entry->before) !== ['goods_sent_at', 'last_proof_attachment_id'] || ! is_string($sentAt)
            || ! is_int($lastFile) || $lastFile < 1
            || \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $sentAt) === false
            || $entry->after !== ['goods_sent_at' => null]
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'rma' || $entry->subjectId === null
            || $entry->reason === null || trim($entry->reason) === '' || mb_strlen($entry->reason) > 500
            || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid proof rejection audit entry.');
        }
    }

    /**
     * 05.4 §14.2 R7: by staff, about the RMA, with a reason; the replacement
     * order from none to the new one.
     */
    private function validateRmaAdvanceReplacement(AuditEntry $entry): void
    {
        $orderId = $entry->after['replacement_order_id'] ?? null;

        if ($entry->before !== ['replacement_order_id' => null]
            || array_keys($entry->after) !== ['replacement_order_id'] || ! is_int($orderId) || $orderId < 1
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'rma' || $entry->subjectId === null
            || $entry->reason === null || trim($entry->reason) === '' || mb_strlen($entry->reason) > 500
            || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid advance replacement audit entry.');
        }
    }

    /** The reason is mandatory and the only payload is the cancellation record. */
    private function validateOrderCancelBelowBreak(AuditEntry $entry): void
    {
        $cancellationId = $entry->after['order_cancellation_id'] ?? null;

        if ($entry->before !== [] || array_keys($entry->after) !== ['order_cancellation_id']
            || ! is_int($cancellationId) || $cancellationId < 1
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'order' || $entry->subjectId === null
            || $entry->companyId === null || $entry->actingForCompanyId !== null
            || $entry->reason === null || trim($entry->reason) === '' || mb_strlen($entry->reason) > 500) {
            throw new InvalidArgumentException('Invalid below-break cancellation audit entry.');
        }
    }

    /**
     * 05.6 §7A.6: by staff, about the payment row. Recording names the order
     * and the amount; a void moves `captured` to `voided` and needs a reason.
     */
    private function validateCashPayment(AuditEntry $entry): void
    {
        $recorded = $entry->action === AuditAction::PaymentCashRecorded;
        $payload = $recorded
            ? $entry->before === [] && array_keys($entry->after) === ['order_id', 'amount_minor']
                && is_int($entry->after['order_id']) && is_int($entry->after['amount_minor']) && $entry->after['amount_minor'] > 0
                && $entry->reason === null
            : $entry->before === ['status' => 'captured'] && $entry->after === ['status' => 'voided']
                && $entry->reason !== null && trim($entry->reason) !== '' && mb_strlen($entry->reason) <= 500;

        if (! $payload || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'payment' || $entry->subjectId === null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid cash payment audit entry.');
        }
    }

    /**
     * 05.6 §7A.11: by staff, about the suspension row, always with a reason.
     * A manual suspension names its customer; a lift records when.
     */
    private function validatePayAtCollectionSuspension(AuditEntry $entry): void
    {
        $payload = $entry->action === AuditAction::PayAtCollectionSuspended
            ? $entry->before === [] && array_keys($entry->after) === ['user_id', 'company_id']
                && (is_int($entry->after['user_id']) !== is_int($entry->after['company_id']))
            : $entry->before === ['lifted_at' => null] && array_keys($entry->after) === ['lifted_at'] && is_string($entry->after['lifted_at']);

        if (! $payload || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== 'pay_at_collection_suspension' || $entry->subjectId === null
            || $entry->reason === null || trim($entry->reason) === '' || mb_strlen($entry->reason) > 500
            || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid pay-at-collection suspension audit entry.');
        }
    }

    /** Staff-made, no subject, the same changed keys on both sides. */
    private function validateStorefrontSettings(AuditEntry $entry): void
    {
        if ($entry->after === [] || array_keys($entry->before) !== array_keys($entry->after)
            || $entry->actorType !== 'user' || $entry->actorUserId === null
            || $entry->subjectType !== null || $entry->reason !== null
            || $entry->companyId !== null || $entry->actingForCompanyId !== null) {
            throw new InvalidArgumentException('Invalid storefront settings audit entry.');
        }
    }

    private function validateCompanyAdministration(AuditEntry $entry): void
    {
        $membership = in_array($entry->action, [AuditAction::CompanyMemberChanged, AuditAction::CompanyMemberRemoved], true);
        $valid = $entry->actorType === 'user' && $entry->actorUserId !== null && $entry->companyId !== null
            && $entry->subjectType === ($membership ? 'user' : 'company_invitation') && $entry->subjectId !== null
            && $entry->reason === null && $entry->actingForCompanyId === null;
        foreach (['before', 'after'] as $side) {
            $payload = $entry->{$side};
            $fields = $side === 'before' ? $entry->action->beforeFields() : $entry->action->afterFields();
            $valid = $valid && count($payload) === count($fields);
            if ($fields !== []) {
                $valid = $valid && is_string($payload['role'] ?? null) && CompanyMemberRole::tryFrom($payload['role']) !== null
                    && array_key_exists('order_limit_minor', $payload)
                    && ($payload['order_limit_minor'] === null || (is_int($payload['order_limit_minor']) && $payload['order_limit_minor'] >= 0))
                    && is_bool($payload['requires_approval'] ?? null);
            }
        }
        if (! $valid) {
            throw new InvalidArgumentException('Invalid company administration audit entry.');
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
