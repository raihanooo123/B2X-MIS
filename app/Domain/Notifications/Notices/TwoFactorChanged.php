<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 05.12 §5.7 `auth.two_factor_changed` — 05.13 §12, §15: two-factor
 * enabled or disabled, or a recovery code used to sign in. (An
 * administrator reset has no code path yet.)
 */
final class TwoFactorChanged extends Notice
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const RECOVERY_CODE_USED = 'recovery_code_used';

    public readonly string $eventId;

    public function __construct(
        public readonly int $userId,
        public readonly string $change,
    ) {
        if (! in_array($change, [self::ENABLED, self::DISABLED, self::RECOVERY_CODE_USED], true)) {
            throw new InvalidArgumentException("Unknown two-factor change '{$change}'.");
        }
        $this->eventId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::TwoFactorChanged;
    }

    public function subject(): array
    {
        return ['user', $this->userId];
    }

    public function occurrence(): string
    {
        return $this->eventId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $user = User::query()->findOrFail($this->userId, ['id', 'first_name']);

        [$subject, $what] = match ($this->change) {
            self::ENABLED => ['Two-factor authentication is on', 'Two-factor authentication was turned on for your account.'],
            self::DISABLED => ['Two-factor authentication is off', 'Two-factor authentication was turned off for your account. Signing in now needs only your password.'],
            default => ['A recovery code was used to sign in', 'A recovery code was used to sign in to your account. That code cannot be used again.'],
        };

        return new MailContent(
            subject: $subject,
            heading: "Hello {$user->first_name},",
            paragraphs: [$what],
            actionLabel: 'Review your account',
            actionUrl: route('account'),
            closing: ['If this was you, no action is needed. If it was not, reset your password now and contact us.'],
        );
    }
}
