<?php

namespace App\Domain\Notifications;

/**
 * The notifications this codebase sends today (05.12 §5). A key is added
 * here when its trigger exists: quotes, returns, back-in-stock,
 * invitations and application review have none yet.
 *
 * `templateVersion()` is recorded on every log row (05.12 §8.2) and bumped
 * whenever that notification's wording changes.
 */
enum NotificationKey: string
{
    case OrderConfirmed = 'order.confirmed';
    case PaymentReceived = 'payment.received';
    case ShipmentDispatched = 'shipment.dispatched';
    case InvoiceIssued = 'invoice.issued';
    case InvoiceDueSoon = 'invoice.due_soon';
    case InvoiceOverdue = 'invoice.overdue';
    case CreditLimitReached = 'credit.limit_reached';
    case CreditLimitWarning = 'credit.limit_warning';
    case ApplicationSubmitted = 'application.submitted';
    case EmailVerification = 'auth.email_verification';
    case ExistingAccount = 'auth.existing_account';
    case PasswordReset = 'auth.password_reset';
    case PasswordChanged = 'auth.password_changed';
    case TwoFactorChanged = 'auth.two_factor_changed';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::ExistingAccount,
            self::PasswordReset,
            self::PasswordChanged,
            self::TwoFactorChanged => NotificationCategory::Security,
            default => NotificationCategory::Transactional,
        };
    }

    public function templateVersion(): string
    {
        return '1';
    }

    /**
     * Attempted even to a hard-bounced address (05.12 §10.4): security
     * notices, and re-verification — the way an address comes back.
     */
    public function ignoresBounceSuppression(): bool
    {
        return $this->category() === NotificationCategory::Security || $this === self::EmailVerification;
    }
}
