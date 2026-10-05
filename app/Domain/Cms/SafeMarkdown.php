<?php

namespace App\Domain\Cms;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * 05.11 §2.2 — the one renderer for admin-authored page text: Markdown
 * only. Raw HTML in the source is escaped, never passed through, so a
 * `<script>` or an `onerror=` attribute is shown as text and never becomes
 * markup; Markdown itself has no syntax for attributes. Links and images
 * to unsafe schemes (`javascript:`, `vbscript:`, `file:`, `data:` other
 * than images) are dropped.
 *
 * Used for every place page text is shown: the public pages
 * (LegalPageController), the admin preview and the version history
 * (CmsPageResource), so no path renders it any other way.
 */
final class SafeMarkdown
{
    public static function toHtml(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    public static function toHtmlString(string $markdown): HtmlString
    {
        return new HtmlString(self::toHtml($markdown));
    }
}
