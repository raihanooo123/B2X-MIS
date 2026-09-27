<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use Illuminate\Support\Str;

/**
 * 05.12 §5.2 `application.already_open` — 05.2 §5.2, 05.13 §5.1: someone
 * applied on the public form with an address that already has an open
 * application. The form showed the normal confirmation (no enumeration);
 * the address learns why nothing new was filed here, and only here. Each
 * attempt is its own occurrence, as for `auth.existing_account`.
 */
final class ApplicationAlreadyOpen extends Notice
{
    public readonly string $requestId;

    public function __construct(public readonly int $openApplicationId)
    {
        $this->requestId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationAlreadyOpen;
    }

    public function subject(): array
    {
        return ['b2b_application', $this->openApplicationId];
    }

    public function occurrence(): string
    {
        return $this->requestId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $application = B2bApplication::query()->findOrFail($this->openApplicationId, ['id', 'contact_name']);

        return new MailContent(
            subject: 'Your trade account application',
            heading: "Hello {$application->contact_name},",
            paragraphs: [
                'Someone — hopefully you — tried to apply for a trade account with this email address.',
                'There is already an application in progress for this address, so we have not started a new one. We will be in touch about the application we have.',
                'If you need to add or correct anything, just reply to this email.',
            ],
            closing: ['If this was not you, no action is needed.'],
        );
    }
}
