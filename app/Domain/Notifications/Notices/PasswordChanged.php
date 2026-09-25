<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use Illuminate\Support\Str;

/** 05.12 §5.7 `auth.password_changed` — 05.13 §15 security notice. */
final class PasswordChanged extends Notice
{
    use FormatsForMail;

    public readonly string $eventId;

    public readonly string $changedAt;

    public function __construct(public readonly int $userId)
    {
        $this->eventId = (string) Str::ulid();
        $this->changedAt = now()->toIso8601String();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::PasswordChanged;
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

        return new MailContent(
            subject: 'Your password has been changed',
            heading: "Hello {$user->first_name},",
            paragraphs: ['The password for your account was changed on '.self::date(new \DateTimeImmutable($this->changedAt)).'. Every other device has been signed out.'],
            actionLabel: 'Reset your password',
            actionUrl: route('password.request'),
            closing: ['If this was you, no action is needed. If it was not, reset your password now and contact us.'],
        );
    }
}
