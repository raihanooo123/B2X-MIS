<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;

/**
 * 05.12 §5.2 `application.submitted` — to the applicant (05.2 §11). The
 * reviewers' copy waits on 05.12 Q6 (which role, which address).
 */
final class ApplicationSubmitted extends Notice
{
    public function __construct(public readonly int $applicationId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationSubmitted;
    }

    public function subject(): array
    {
        return ['b2b_application', $this->applicationId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $application = B2bApplication::query()->findOrFail($this->applicationId, ['id', 'company_name']);

        return new MailContent(
            subject: 'We have received your trade account application',
            heading: 'Application received',
            paragraphs: [
                "Thank you for applying for a trade account for {$application->company_name}.",
                'Please confirm your email address using the separate email we have sent — we review applications once the address is confirmed.',
                'Until then you can sign in, browse the catalogue at our standard prices and check your application status.',
            ],
        );
    }
}
