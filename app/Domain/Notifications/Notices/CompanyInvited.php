<?php

namespace App\Domain\Notifications\Notices;

use App\Domain\Notifications\MailContent;
use App\Domain\Notifications\Notice;
use App\Domain\Notifications\NotificationKey;
use App\Domain\Notifications\Recipient;
use App\Models\CompanyInvitation;
use Illuminate\Support\Facades\Crypt;

final class CompanyInvited extends Notice
{
    /** Keep the bearer token encrypted in queued/failed job payloads too. */
    public readonly string $encryptedToken;

    public function __construct(public readonly int $invitationId, string $token)
    {
        $this->encryptedToken = Crypt::encryptString($token);
    }

    public function key(): NotificationKey
    {
        return NotificationKey::CompanyInvitation;
    }

    public function subject(): array
    {
        return ['company_invitation', $this->invitationId];
    }

    public function content(Recipient $recipient): MailContent
    {
        $invitation = CompanyInvitation::query()->with('company')->findOrFail($this->invitationId);

        return new MailContent(
            subject: 'Invitation to '.$invitation->company->name,
            heading: 'Hello '.$invitation->first_name.',',
            paragraphs: ['You have been invited to order for '.$invitation->company->name.' as '.self::roleWithArticle($invitation->role).'.',
                'Open the invitation to accept. If you already have an account with this email address, you will be asked to sign in; otherwise you choose a password.'],
            actionLabel: 'View invitation',
            actionUrl: route('company-invitations.show', ['token' => Crypt::decryptString($this->encryptedToken)]),
            closing: ['This link expires in seven days. Only the newest invitation link works.'],
        );
    }

    private static function roleWithArticle(string $role): string
    {
        return (in_array($role[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').$role;
    }
}
