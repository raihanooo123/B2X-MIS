<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\CompanyInvitation;

final class CompanyInvitationAccepted extends Notice
{
    public function __construct(public readonly int $invitationId) {}

    public function key(): NotificationKey
    {
        return NotificationKey::CompanyInvitationAccepted;
    }

    public function subject(): array
    {
        return ['company_invitation', $this->invitationId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $invitation = CompanyInvitation::query()->with('company')->findOrFail($this->invitationId);

        return new MailContent(
            subject: 'Company invitation accepted', heading: 'Invitation accepted',
            paragraphs: [$invitation->first_name.' '.$invitation->last_name.' accepted your invitation to '.$invitation->company->name.'.'],
            actionLabel: 'Your account', actionUrl: route('account'),
        );
    }
}
