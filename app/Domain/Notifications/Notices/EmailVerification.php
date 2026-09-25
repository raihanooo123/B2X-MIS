<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Identity\EmailVerificationLink;
use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 05.12 §5.7 `auth.email_verification` — 05.13 §11. Each request is its
 * own message (a resend is not a duplicate), and it is attempted even to
 * a hard-bounced address: it is how an address comes back (05.12 §10.4).
 */
final class EmailVerification extends Notice
{
    public readonly string $requestId;

    public function __construct(public readonly int $userId)
    {
        $this->requestId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::EmailVerification;
    }

    public function subject(): array
    {
        return ['user', $this->userId];
    }

    public function occurrence(): string
    {
        return $this->requestId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $user = User::query()->findOrFail($this->userId);

        return new MailContent(
            subject: 'Confirm your email address',
            heading: "Hello {$user->first_name},",
            paragraphs: ['Please confirm this is your email address.'],
            actionLabel: 'Confirm email address',
            actionUrl: EmailVerificationLink::url($user),
            closing: ['The link expires in 24 hours. If you did not create an account, you can ignore this email.'],
        );
    }
}
