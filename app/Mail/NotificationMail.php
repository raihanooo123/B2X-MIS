<?php

namespace App\Mail;

use App\Domain\Notifications\MailContent;
use App\Models\Attachment as ArchivedFile;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every notification email (05.12 §13): the shared HTML and plain-text
 * views rendering one MailContent. An archived document is attached from
 * storage as it is — the archive and the attachment are the same bytes
 * (02 §21.1).
 */
class NotificationMail extends Mailable
{
    public function __construct(
        public readonly MailContent $mailContent,
        public readonly ?ArchivedFile $archivedFile = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailContent->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
            with: ['mail' => $this->mailContent],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->archivedFile === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk($this->archivedFile->disk, $this->archivedFile->path)
                ->as($this->archivedFile->original_name ?? basename($this->archivedFile->path))
                ->withMime($this->archivedFile->mime_type),
        ];
    }
}
