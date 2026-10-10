<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use Illuminate\Support\Str;

/**
 * 05.17 §2 `application.reply_received` — to the reviewer who asked for
 * more information: the applicant has answered and the application is back
 * in the review queue. The reply itself is read in the admin panel, not
 * copied into email.
 */
final class ApplicationReplyReceived extends Notice
{
    public readonly string $eventId;

    public function __construct(public readonly int $applicationId)
    {
        $this->eventId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationReplyReceived;
    }

    public function subject(): array
    {
        return ['b2b_application', $this->applicationId];
    }

    public function occurrence(): string
    {
        return $this->eventId;
    }

    public function content(Recipient $recipient): MailContent
    {
        $application = B2bApplication::query()->findOrFail($this->applicationId, ['id', 'company_name']);

        return new MailContent(
            subject: "{$application->company_name} has replied to your information request",
            heading: 'An applicant has replied',
            paragraphs: [
                "{$application->company_name} has answered your request for more information about their trade account application.",
                'The application is back in review. Open it in the admin panel under Customers → Trade applications to read the reply and any documents.',
            ],
        );
    }
}
