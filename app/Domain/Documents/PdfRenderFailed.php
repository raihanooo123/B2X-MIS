<?php

namespace App\Domain\Documents;

use RuntimeException;
use Throwable;

/**
 * A render that produced no archivable PDF, classified (05.17 §3). The code
 * is stored on the render and is safe to show; the technical detail is
 * logged, never sent to a customer.
 */
final class PdfRenderFailed extends RuntimeException
{
    /** No renderer configured: the null renderer never counts as success. */
    public const RENDERER_UNAVAILABLE = 'renderer_unavailable';

    /** The browser failed, timed out or could not start. */
    public const RENDERER_ERROR = 'renderer_error';

    /** The bytes are not a PDF. */
    public const NOT_A_PDF = 'not_a_pdf';

    /** Over the size or page limit. */
    public const TOO_LARGE = 'too_large';

    /** The archived bytes did not read back identically. */
    public const ARCHIVE_MISMATCH = 'archive_mismatch';

    /** The source has no payload captured at issue (05.17 §3 backfill blocker). */
    public const MISSING_SNAPSHOT = 'missing_snapshot';

    /** The payload names a template this application does not have. */
    public const UNKNOWN_TEMPLATE = 'unknown_template';

    public function __construct(public readonly string $reason, string $detail = '', ?Throwable $previous = null)
    {
        parent::__construct($detail === '' ? $reason : "{$reason}: {$detail}", 0, $previous);
    }

    /** What a customer is told, in plain words. Never the technical detail. */
    public static function customerMessage(?string $reason): string
    {
        return match ($reason) {
            self::MISSING_SNAPSHOT => 'This document was issued before PDFs were archived, so a copy cannot be produced here. Contact accounts for a copy.',
            self::RENDERER_UNAVAILABLE => 'PDFs cannot be prepared at the moment. Try again later.',
            default => 'The PDF could not be prepared. Try again; if it fails again, contact accounts.',
        };
    }
}
