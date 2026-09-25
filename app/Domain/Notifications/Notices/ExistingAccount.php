<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 05.12 §5.7 `auth.existing_account` — 05.13 §5.1: someone registered with
 * an address that already has an account. The form showed the normal
 * confirmation (no enumeration); the address's owner learns the truth here.
 */
final class ExistingAccount extends Notice
{
    public readonly string $requestId;

    public function __construct(public readonly int $userId)
    {
        $this->requestId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::ExistingAccount;
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
        $user = User::query()->findOrFail($this->userId, ['id', 'first_name']);

        return new MailContent(
            subject: 'You already have an account',
            heading: "Hello {$user->first_name},",
            paragraphs: ['Someone — hopefully you — tried to register with this email address, which already has an account.'],
            actionLabel: 'Sign in',
            actionUrl: route('login'),
            closing: ['Signed in, you can apply for a trade account from your account. If you have forgotten your password, use "Forgot password" on the sign-in page. If this was not you, no action is needed.'],
        );
    }
}
