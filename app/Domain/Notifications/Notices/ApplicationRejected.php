<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;

/**
 * 05.12 §5.2 `application.rejected` (05.2 §5.7, §11; 02 §25.2). The
 * reviewer's message to the applicant when one was written, otherwise
 * neutral wording. The internal reason (`review_note`) and the category
 * are never included. The re-application date is the `reapply_after`
 * snapshot, so the email and the re-application gate always agree.
 */
final class ApplicationRejected extends Notice
{
    use FormatsForMail;

    public const DEFAULT_MESSAGE = 'We are not able to open a trade account for you at the moment.';

    public function __construct(public readonly int $applicationId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::ApplicationRejected;
    }

    public function subject(): array
    {
        return ['b2b_application', $this->applicationId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $application = B2bApplication::query()->findOrFail($this->applicationId, ['id', 'company_name', 'contact_name', 'applicant_message', 'reapply_after']);

        $reapply = $application->reapply_after === null
            ? 'You are welcome to apply again once your circumstances change. If you would like to talk it through first, just reply to this email.'
            : 'You are welcome to apply again from '.self::date($application->reapply_after).'. If you would like to talk it through first, just reply to this email.';

        return new MailContent(
            subject: 'Your trade account application',
            heading: "Hello {$application->contact_name},",
            paragraphs: [
                "Thank you for applying for a trade account for {$application->company_name}.",
                $application->applicant_message ?? self::DEFAULT_MESSAGE,
                'You can still sign in and buy at our standard prices, paying by card.',
                $reapply,
            ],
            actionLabel: 'Sign in',
            actionUrl: route('login'),
        );
    }
}
