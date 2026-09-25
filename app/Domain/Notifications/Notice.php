<?php

namespace App\Domain\Notifications;

/**
 * One notification about one subject, sent through NotificationDispatcher.
 * Holds ids and scalars only — it is serialised onto the queue — and
 * builds its content at send time from an explicit payload, never by
 * handing a model to a template (05.12 §7.2, §13).
 */
abstract class Notice
{
    abstract public function key(): NotificationKey;

    abstract public function content(Recipient $recipient): MailContent;

    /**
     * The record this message is about, e.g. ['invoice', 12].
     *
     * @return array{0: string, 1: int}|null
     */
    public function subject(): ?array
    {
        return null;
    }

    /**
     * Distinguishes repeat messages of one key about one subject: empty for
     * one-off messages, the date for daily reminders (05.12 §8.2).
     */
    public function occurrence(): string
    {
        return '';
    }

    /** An archived `attachments` row to attach (05.12 §12). */
    public function attachmentId(): ?int
    {
        return null;
    }

    public function dedupKey(Recipient $recipient): string
    {
        [$type, $id] = $this->subject() ?? ['', ''];

        return implode(':', [$this->key()->value, $type, $id, $recipient->email, $this->occurrence()]);
    }
}
