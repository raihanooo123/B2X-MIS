<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use Illuminate\Support\Str;

/**
 * 05.12 §5.2 `application.info_requested` — the reviewer's request, to
 * the applicant (05.2 §5.5). A request can be made more than once per
 * application, so each is its own occurrence.
 *
 * 05.12 names a tokenised link to a response form; that form is not built
 * (05.13 §20), so the email asks the applicant to reply instead.
 */
final class ApplicationInfoRequested extends Notice
{
    public readonly string $eventId;

    public function __construct(
        public readonly int $applicationId,
        public readonly string $request,
    ) {
        $this->eventId = (string) Str::ulid();
    }

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationInfoRequested;
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
        $application = B2bApplication::query()->findOrFail($this->applicationId, ['id', 'company_name', 'contact_name']);

        return new MailContent(
            subject: 'We need a little more information about your trade account application',
            heading: "Hello {$application->contact_name},",
            paragraphs: [
                "Thank you for applying for a trade account for {$application->company_name}. Before we can finish reviewing it, we need the following:",
                $this->request,
                'Please reply to this email with the information or documents. Your application stays open while we wait for them.',
            ],
        );
    }
}
