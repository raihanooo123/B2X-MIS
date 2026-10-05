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

    /** 05.15 §3.1: storefront branding, the changed `brand.*` keys only. */
    case StorefrontSettingsChanged = 'configuration.storefront_settings_changed';

    /** 05.11 §2.4: a legal or help page version published. Never the text. */
    case PagePublished = 'content.page_published';

    /*
     * 05.15 §6.3: a guest's order attached to the account that verified
     * its email. Changes who may see the order, so `permission`.
     */
    case OrderClaimed = 'order.claimed';

    /*
     * 05.4 §13.5: staff reject a consumer's proof of sending. The files are
     * kept as evidence; this entry is the record of who rejected it, when
     * and why (`reason`). A decision on a return, so `rma_disposition`.
     */
    case RmaProofRejected = 'rma.proof_rejected';

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
            self::ApplicationRejected, self::ApplicationApproved, self::ApplicationVerificationRequested,
            self::OrderClaimed => 'permission',
            self::RmaProofRejected => 'rma_disposition',
            self::CreditLimitChanged => 'credit_limit',
            self::TermsVersionPublished, self::StorefrontSettingsChanged, self::PagePublished => 'configuration',
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
            self::TermsVersionPublished, self::PagePublished => [],
            self::OrderClaimed => ['user_id'],
            self::RmaProofRejected => ['goods_sent_at', 'last_proof_attachment_id'],
            self::StorefrontSettingsChanged => ['brand.name', 'brand.tagline', 'brand.logo_path', 'brand.primary_colour',
                'brand.support_email', 'brand.support_phone', 'brand.show_powered_by', 'seo.indexing_enabled'],
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
            self::OrderClaimed => ['user_id'],
            self::RmaProofRejected => ['goods_sent_at'],
            self::TermsVersionPublished => ['kind', 'version', 'effective_from', 'body_sha256'],
            self::PagePublished => ['page_key', 'version_no', 'effective_from', 'body_sha256'],
            self::StorefrontSettingsChanged => ['brand.name', 'brand.tagline', 'brand.logo_path', 'brand.primary_colour',
                'brand.support_email', 'brand.support_phone', 'brand.show_powered_by', 'seo.indexing_enabled'],
            self::SignedIn => ['method'],
            self::SessionExpired => ['reason'],
            self::LockedOut => ['identifier_fingerprint', 'key_version', 'locked_seconds'],
            self::RecoveryCodeUsed => ['remaining'],
            self::SignedOut, self::PasswordResetRequested, self::PasswordResetCompleted,
            self::TwoFactorEnabled, self::TwoFactorDisabled, self::RecoveryCodesRegenerated => [],
        };
    }
}
