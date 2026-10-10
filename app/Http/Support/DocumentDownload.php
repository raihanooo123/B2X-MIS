<?php

namespace App\Http\Support;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 05.17 §4: a private PDF download. Callers have already re-checked
 * membership and the source policy on this request. A ready archive is
 * streamed through the application (never a public storage URL) with
 * `Cache-Control: private, no-store`. Not ready: 409 and the source page
 * to prepare it from — a GET never queues a render.
 */
final class DocumentDownload
{
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public static function respond(?Attachment $attachment, string $sourceUrl): Response
    {
        if ($attachment === null) {
            return response("This PDF is not ready yet. Prepare it from {$sourceUrl}", 409, [
                ...self::HEADERS,
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Link' => "<{$sourceUrl}>; rel=\"related\"",
            ]);
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name ?? 'document.pdf', [
            ...self::HEADERS,
            'Content-Type' => 'application/pdf',
        ]);
    }
}
