<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use Illuminate\Support\Str;

/**
 * 05.12 §5.2 `application.reapply_blocked` — 05.13 §5.1, §7: someone
 * applied on the public form with an address whose latest rejection is
 * still in its cooling period. The form showed the normal confirmation
 * (no enumeration); the address learns the date here, and only here.
 * Each attempt is its own occurrence, as for `auth.existing_account`.
 */
final class ApplicationReapplyBlocked extends Notice
{
    use FormatsForMail;

    public readonly string $requestId;

    public function __construct(public readonly int $rejectedApplicationId)
    {
        $this->requestId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationReapplyBlocked;
    }

    public function subject(): array
    {
        return ['b2b_application', $this->rejectedApplicationId];
    }

    public function occurrence(): string
    {
        return $this->requestId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $application = B2bApplication::query()->findOrFail($this->rejectedApplicationId, ['id', 'contact_name', 'reapply_after']);

        return new MailContent(
            subject: 'Your trade account application',
            heading: "Hello {$application->contact_name},",
            paragraphs: [
                'Someone — hopefully you — tried to apply for a trade account with this email address.',
                'We reviewed an earlier application from this address recently, so a new one can be made from '.self::date($application->reapply_after).'.',
                'Meanwhile you can create a customer account and buy at our standard prices. If you would like to talk it through, just reply to this email.',
            ],
            closing: ['If this was not you, no action is needed.'],
        );
    }
}
