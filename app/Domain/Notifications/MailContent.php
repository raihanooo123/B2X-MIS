<?php

namespace App\Domain\Notifications;

/**
 * What one email says, rendered by the shared mail views (05.12 §13):
 * `resources/views/mail/notification.blade.php` and its plain-text twin.
 * Money arrives already formatted (06 §3.1) — templates do no arithmetic.
 */
final readonly class MailContent
{
    /**
     * @param  list<string>  $paragraphs
     * @param  list<array{label: string, value: string}>  $facts
     * @param  list<string>  $closing
     * @param  list<array{heading: string, paragraphs: list<string>}>  $sections  headed blocks after the action, before the closing (05.15 §7.1)
     */
    public function __construct(
        public string $subject,
        public string $heading,
        public array $paragraphs = [],
        public array $facts = [],
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public array $closing = [],
        public array $sections = [],
    ) {}
}
