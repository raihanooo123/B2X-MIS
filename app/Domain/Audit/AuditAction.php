<?php

namespace App\Domain\Audit;

enum AuditAction: string
{
    case CompanyInvited = 'permission.company_invited';

    case CompanyInvitationRevoked = 'permission.company_invitation_revoked';

    case CompanyInvitationAccepted = 'permission.company_invitation_accepted';

    case CompanyMemberChanged = 'permission.company_member_changed';

    case CompanyMemberRemoved = 'permission.company_member_removed';

    case SignInFailed = 'auth.sign_in_failed';

    case StaffCreated = 'auth.staff_created';

    case StaffOnboardingRequested = 'auth.staff_onboarding_requested';

    case StaffRoleGranted = 'permission.staff_role_granted';

    case StaffRoleRevoked = 'permission.staff_role_revoked';

    case StaffSuspended = 'auth.staff_suspended';

    case StaffReinstated = 'auth.staff_reinstated';

    case StaffTwoFactorReset = 'auth.staff_two_factor_reset';

    case CustomerSuspended = 'auth.customer_suspended';

    case CustomerReinstated = 'auth.customer_reinstated';

    /*
     * 05.13 §15: a person's own authentication events. The actor is the
     * person (or anonymous, before they are known); nothing secret — no
     * password, code, token or raw identifier — is ever recorded.
     */
    case SignedIn = 'auth.signed_in';

    case SignedOut = 'auth.signed_out';

    case SessionExpired = 'auth.session_expired';

    case LockedOut = 'auth.locked_out';

    case PasswordResetRequested = 'auth.password_reset_requested';

    case PasswordResetCompleted = 'auth.password_reset_completed';

    case TwoFactorEnabled = 'auth.two_factor_enabled';

    case TwoFactorDisabled = 'auth.two_factor_disabled';

    case RecoveryCodesRegenerated = 'auth.recovery_codes_regenerated';

    case RecoveryCodeUsed = 'auth.recovery_code_used';

    /*
     * 05.2 §4: every application transition, with actor and time. 07 §6.5
     * has no application family; review decides who may buy on trade
     * terms, so these sit under `permission`.
     */
    case ApplicationReviewStarted = 'application.review_started';

    case ApplicationInfoRequested = 'application.info_requested';

    case ApplicationReviewResumed = 'application.review_resumed';

    case ApplicationRejected = 'application.rejected';

    case ApplicationApproved = 'application.approved';

    /** 02 §25.4: a reviewer re-ran the verification checks. */
    case ApplicationVerificationRequested = 'application.verification_requested';

    case CreditLimitChanged = 'credit_limit.changed';

    /** 02 §25.1: 07 §6.5 "configuration changes". */
    case TermsVersionPublished = 'configuration.terms_version_published';

    public function family(): string
    {
        return match ($this) {
            self::SignInFailed, self::StaffCreated, self::StaffOnboardingRequested,
            self::StaffSuspended, self::StaffReinstated, self::StaffTwoFactorReset,
            self::CustomerSuspended, self::CustomerReinstated,
            self::SignedIn, self::SignedOut, self::SessionExpired, self::LockedOut,
            self::PasswordResetRequested, self::PasswordResetCompleted,
            self::TwoFactorEnabled, self::TwoFactorDisabled,
            self::RecoveryCodesRegenerated, self::RecoveryCodeUsed => 'auth',
            self::CompanyInvited, self::CompanyInvitationRevoked, self::CompanyInvitationAccepted,
            self::CompanyMemberChanged, self::CompanyMemberRemoved,
            self::StaffRoleGranted, self::StaffRoleRevoked,
            self::ApplicationReviewStarted, self::ApplicationInfoRequested, self::ApplicationReviewResumed,
            self::ApplicationRejected, self::ApplicationApproved, self::ApplicationVerificationRequested => 'permission',
            self::CreditLimitChanged => 'credit_limit',
            self::TermsVersionPublished => 'configuration',
        };
    }

    /** @return list<string> */
    public function beforeFields(): array
    {
        return match ($this) {
            self::CompanyInvited, self::CompanyInvitationRevoked, self::CompanyInvitationAccepted => [],
            self::CompanyMemberChanged, self::CompanyMemberRemoved => ['role', 'order_limit_minor', 'requires_approval'],
            self::SignInFailed, self::StaffCreated, self::StaffOnboardingRequested, self::StaffRoleGranted => [],
            self::StaffRoleRevoked => ['role', 'granted_by_user_id'],
            self::StaffSuspended, self::StaffReinstated,
            self::CustomerSuspended, self::CustomerReinstated => ['status'],
            self::StaffTwoFactorReset => ['two_factor_enabled'],
            self::ApplicationReviewStarted, self::ApplicationInfoRequested, self::ApplicationReviewResumed,
            self::ApplicationRejected, self::ApplicationApproved => ['status'],
            self::ApplicationVerificationRequested => [],
            self::CreditLimitChanged => ['credit_limit_minor'],
            self::TermsVersionPublished => [],
            self::SignedIn, self::SignedOut, self::SessionExpired, self::LockedOut,
            self::PasswordResetRequested, self::PasswordResetCompleted,
            self::TwoFactorEnabled, self::TwoFactorDisabled,
            self::RecoveryCodesRegenerated, self::RecoveryCodeUsed => [],
        };
    }

    /** @return list<string> */
    public function afterFields(): array
    {
        return match ($this) {
            self::CompanyInvited, self::CompanyMemberChanged => ['role', 'order_limit_minor', 'requires_approval'],
            self::CompanyInvitationRevoked, self::CompanyInvitationAccepted, self::CompanyMemberRemoved => [],
            self::SignInFailed => ['identifier_fingerprint', 'key_version'],
            self::StaffCreated => ['status'],
            self::StaffOnboardingRequested => ['channel'],
            self::StaffRoleGranted => ['role'],
            self::StaffRoleRevoked => [],
            self::StaffSuspended, self::StaffReinstated,
            self::CustomerSuspended, self::CustomerReinstated => ['status'],
            self::StaffTwoFactorReset => ['two_factor_enabled'],
            self::ApplicationReviewStarted, self::ApplicationInfoRequested, self::ApplicationReviewResumed => ['status'],
            self::ApplicationRejected => ['status', 'remediable', 'rejection_category'],
            self::ApplicationApproved => ['status', 'company_id', 'owner_user_id', 'price_tier_id', 'payment_terms', 'credit_limit_minor',
                'verification_warnings', 'verification_acknowledged'],
            self::ApplicationVerificationRequested => ['checks'],
            self::CreditLimitChanged => ['credit_limit_minor'],
            self::TermsVersionPublished => ['kind', 'version', 'effective_from', 'body_sha256'],
            self::SignedIn => ['method'],
            self::SessionExpired => ['reason'],
            self::LockedOut => ['identifier_fingerprint', 'key_version', 'locked_seconds'],
            self::RecoveryCodeUsed => ['remaining'],
            self::SignedOut, self::PasswordResetRequested, self::PasswordResetCompleted,
            self::TwoFactorEnabled, self::TwoFactorDisabled, self::RecoveryCodesRegenerated => [],
        };
    }
}
