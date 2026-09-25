<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\User;

/**
 * 05.12 §5.7 `auth.password_reset` — 05.13 §10, and the "set your
 * password" link for a new staff user (§5.3), the same broker token.
 * Single use, 60 minutes (07 §6.1). Keyed by the token's hash, so the log
 * never holds the token itself.
 */
final class PasswordReset extends Notice
{
    public function __construct(
        public readonly int $userId,
        public readonly string $token,
    ) {}

    public function key(): NotificationKey
    {
        return NotificationKey::PasswordReset;
    }

    public function subject(): array
    {
        return ['user', $this->userId];
    }

    public function occurrence(): string
    {
        return substr(hash('sha256', $this->token), 0, 16);
    }

    public function content(Recipient $recipient): MailContent
    {
        $user = User::query()->findOrFail($this->userId, ['id', 'first_name', 'email', 'status']);
        $settingUp = $user->status === 'pending';

        return new MailContent(
            subject: $settingUp ? 'Set your password' : 'Reset your password',
            heading: "Hello {$user->first_name},",
            paragraphs: [$settingUp ? 'An account has been created for you. Choose a password to start.' : 'We received a request to reset your password.'],
            actionLabel: $settingUp ? 'Set password' : 'Reset password',
            actionUrl: route('password.reset', ['token' => $this->token, 'email' => $user->email]),
            closing: ['This link can be used once and expires in 60 minutes. If you did not ask for this, you can ignore this email.'],
        );
    }
}
