<?php

namespace App\Domain\Documents;

use App\Jobs\RenderDocument;
use App\Models\Attachment;
use App\Models\DocumentRender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * 05.17 §3 — archived documents. A source (invoice, credit note,
 * statement) has its payload **captured once, at issue**: seller and
 * customer identity, lines, integer amounts with their formatted text,
 * and the template version. Every PDF of that version is printed from
 * that payload, so a later change to the company, the branding or the
 * seller details never changes a reprint.
 *
 * Status: pending → rendering → ready | failed. A failed render is retried
 * with the same payload and the same row — never another document number.
 * The renderer runs outside any transaction; only the status changes and
 * the archive link are written in short ones. A crashed worker leaves the
 * row `rendering`, and the queue's retry picks it up again: the lease is
 * the queue's, not a column (05.17 §3).
 *
 * A null renderer, or bytes that are not a valid PDF, is a classified
 * failure (PdfRenderFailed), never a ready archive.
 */
final class DocumentRenders
{
    public const SCHEMA_VERSION = 1;

    /** document_type => attachable_type of its archive */
    private const ATTACHABLE = [
        'invoice' => 'invoice',
        'credit_note' => 'credit_note',
        'statement' => 'statement',
    ];

    public function __construct(private readonly PdfRenderer $renderer) {}

    /**
     * The payload for a source, captured once. A repeat call — the issue
     * hook running twice, a queue retry — returns the existing version.
     */
    public function capture(string $type, int $sourceId, ?int $companyId, PdfDocument $document, ?int $requestedByUserId = null): DocumentRender
    {
        if (! isset(self::ATTACHABLE[$type]) || $document->template() !== $type) {
            throw new LogicException("Cannot capture a {$type} render from a {$document->template()} document.");
        }

        $existing = $this->latest($type, $sourceId);
        if ($existing !== null) {
            return $existing;
        }

        $templateVersion = (string) config("documents.template_versions.{$type}");
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'document_type' => $type,
            'template_version' => $templateVersion,
            'captured_at' => now()->toIso8601ZuluString(),
            ...$document->toArray(),
        ];

        try {
            return DB::transaction(fn () => DocumentRender::query()->create([
                'company_id' => $companyId,
                'document_type' => $type,
                'source_id' => $sourceId,
                'version' => 1,
                'template_version' => $templateVersion,
                'payload' => $payload,
                'payload_sha256' => self::hash($payload),
                'status' => DocumentRender::PENDING,
                'requested_by_user_id' => $requestedByUserId,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent capture won; it is the same document.
            return $this->latest($type, $sourceId) ?? throw new LogicException("Render for {$type} {$sourceId} vanished.");
        }
    }

    public function latest(string $type, int $sourceId): ?DocumentRender
    {
        return DocumentRender::query()->where('document_type', $type)->where('source_id', $sourceId)
            ->orderByDesc('version')->first();
    }

    /** The latest ready archive of a source, or null. */
    public function readyAttachment(string $type, int $sourceId): ?Attachment
    {
        $render = DocumentRender::query()->where('document_type', $type)->where('source_id', $sourceId)
            ->where('status', DocumentRender::READY)->orderByDesc('version')->with('attachment')->first();

        return $render?->attachment;
    }

    /**
     * The explicit "prepare" (POST /api/v1/document-renders): queue the
     * render unless it is ready or already queued. Never a GET's job.
     */
    public function queue(DocumentRender $render): DocumentRender
    {
        if ($render->status === DocumentRender::READY) {
            return $render;
        }

        if ($render->status === DocumentRender::FAILED) {
            $render = $this->retry($render);
        }

        RenderDocument::dispatch($render->id);

        return $render->refresh();
    }

    /** A failed render back to pending, same payload and row (05.17 §3). */
    public function retry(DocumentRender $render): DocumentRender
    {
        DB::transaction(function () use ($render): void {
            DB::table('document_renders')->where('id', $render->id)->where('status', DocumentRender::FAILED)
                ->update(['status' => DocumentRender::PENDING, 'error_code' => null]);
        });

        return $render->refresh();
    }

    /**
     * Render and archive one version. Run by RenderDocument.
     *
     * @throws PdfRenderFailed when the failure is worth the queue retrying (the browser itself failed)
     */
    public function render(int $renderId): DocumentRender
    {
        $render = DB::transaction(function () use ($renderId): ?DocumentRender {
            $locked = DocumentRender::query()->whereKey($renderId)->lockForUpdate()->first();
            if ($locked === null || $locked->status === DocumentRender::READY) {
                return $locked;
            }
            $locked->forceFill(['status' => DocumentRender::RENDERING, 'error_code' => null])->save();

            return $locked;
        });

        if ($render === null || $render->status === DocumentRender::READY) {
            return $render ?? throw new LogicException("No document render {$renderId}.");
        }

        try {
            if (! hash_equals($render->payload_sha256, self::hash($render->payload))) {
                throw new PdfRenderFailed(PdfRenderFailed::ARCHIVE_MISMATCH, 'payload hash');
            }

            $bytes = $this->renderer->render(new ArchivedDocument($render->document_type, $render->payload));
            if ($bytes === null) {
                throw new PdfRenderFailed(PdfRenderFailed::RENDERER_UNAVAILABLE);
            }
            PdfBytes::validate($bytes);

            return $this->archive($render, $bytes);
        } catch (PdfRenderFailed $e) {
            $this->fail($render, $e);

            // Only a browser failure might succeed on the queue's next try.
            if ($e->reason === PdfRenderFailed::RENDERER_ERROR) {
                throw $e;
            }

            return $render->refresh();
        }
    }

    /** Write, read back, then link — the archive is exactly the validated bytes. */
    private function archive(DocumentRender $render, string $bytes): DocumentRender
    {
        $disk = (string) config('documents.disk');
        $path = "documents/{$render->document_type}/{$render->public_id}.pdf";
        $checksum = hash('sha256', $bytes);

        Storage::disk($disk)->put($path, $bytes);
        $stored = Storage::disk($disk)->get($path);
        if ($stored === null || ! hash_equals($checksum, hash('sha256', $stored))) {
            throw new PdfRenderFailed(PdfRenderFailed::ARCHIVE_MISMATCH, 'stored bytes');
        }

        return DB::transaction(function () use ($render, $disk, $path, $bytes): DocumentRender {
            $locked = DocumentRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === DocumentRender::READY) {
                return $locked;
            }

            $attachment = Attachment::query()->create([
                'attachable_type' => self::ATTACHABLE[$locked->document_type],
                'attachable_id' => $locked->source_id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => self::fileName($locked),
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'is_customer_visible' => true,
                'uploaded_by_user_id' => null,
            ]);

            $locked->forceFill([
                'status' => DocumentRender::READY,
                'attachment_id' => $attachment->id,
                'rendered_at' => now(),
                'error_code' => null,
            ])->save();

            return $locked;
        });
    }

    private function fail(DocumentRender $render, PdfRenderFailed $e): void
    {
        // Safe metrics only: ids and the classified reason, no payload or PII.
        Log::warning('Document render failed.', [
            'render_id' => $render->id,
            'document_type' => $render->document_type,
            'reason' => $e->reason,
            'detail' => $e->getPrevious()?->getMessage() === null ? null : mb_substr((string) $e->getPrevious()->getMessage(), 0, 500),
        ]);

        DB::table('document_renders')->where('id', $render->id)->where('status', '<>', DocumentRender::READY)
            ->update(['status' => DocumentRender::FAILED, 'error_code' => $e->reason]);
    }

    /** The download name: the document's own number, never an internal id. */
    private static function fileName(DocumentRender $render): string
    {
        $number = $render->payload['number'] ?? null;

        return (is_string($number) && $number !== '' ? preg_replace('/[^A-Za-z0-9._-]/', '-', $number) : $render->public_id).'.pdf';
    }

    /**
     * SHA-256 of the payload in canonical JSON (keys sorted at every
     * level), so the hash survives jsonb's own key ordering on read-back.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        return hash('sha256', (string) json_encode(self::canonical($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value);

        return array_map(self::canonical(...), $value);
    }
}
