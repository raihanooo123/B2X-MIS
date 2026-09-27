<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\B2bApplication;
use Illuminate\Support\Carbon;

/**
 * 05.12 §5.2 `application.rejected` — neutral wording (05.2 §5.7, §11).
 * The internal reason is never included. The re-application date follows
 * the cooling period unless the reviewer marked the rejection remediable.
 */
final class ApplicationRejected extends Notice
{
    use FormatsForMail;

    public function __construct(
        public readonly int $applicationId,
        public readonly bool $remediable,
        public readonly ?string $reapplyFrom,
    ) {}

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
        $application = B2bApplication::query()->findOrFail($this->applicationId, ['id', 'company_name', 'contact_name']);

        $reapply = $this->remediable || $this->reapplyFrom === null
            ? 'You are welcome to apply again once your circumstances change. If you would like to talk it through first, just reply to this email.'
            : 'You are welcome to apply again from '.self::date(Carbon::parse($this->reapplyFrom)).'. If you would like to talk it through first, just reply to this email.';

        return new MailContent(
            subject: 'Your trade account application',
            heading: "Hello {$application->contact_name},",
            paragraphs: [
                "Thank you for applying for a trade account for {$application->company_name}. We are not able to open a trade account for you at the moment.",
                'You can still sign in and buy at our standard prices, paying by card.',
                $reapply,
            ],
            actionLabel: 'Sign in',
            actionUrl: route('login'),
        );
    }
}
