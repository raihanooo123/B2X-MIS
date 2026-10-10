<?php

namespace App\Domain\Documents;

use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Renderer A (05.17 §5): the payload is laid out by its Blade print
 * template (resources/views/documents/), then printed to A4 by Puppeteer's
 * pinned Chromium through spatie/browsershot.
 *
 * Hardening, all on every render:
 *   - the HTML is built here from the trusted payload only, every value
 *     escaped by Blade; no customer URL, HTML or path ever reaches it;
 *   - the page carries a Content-Security-Policy of `default-src 'none'`
 *     with inline styles and `data:` fonts/images only, so Chromium fetches
 *     nothing from the network or the filesystem;
 *   - JavaScript and redirects are disabled;
 *   - Chromium keeps its sandbox (never `--no-sandbox`) and a time limit.
 *     Run the queue worker as an unprivileged user.
 */
final class BrowsershotPdfRenderer implements PdfRenderer
{
    public const TEMPLATES = ['invoice', 'credit_note', 'statement'];

    public function render(PdfDocument $document): string
    {
        $html = self::html($document);

        try {
            $browsershot = Browsershot::html($html)
                ->format('A4')
                ->margins(14, 12, 16, 12)
                ->showBackground()
                ->emulateMedia('print')
                ->disableJavascript()
                ->disableRedirects()
                ->disableCaptureURLS()
                ->timeout((int) config('documents.browsershot.timeout_seconds'));

            $config = (array) config('documents.browsershot');
            if (is_string($config['node_binary'] ?? null) && $config['node_binary'] !== '') {
                $browsershot->setNodeBinary($config['node_binary']);
            }
            if (is_string($config['npm_binary'] ?? null) && $config['npm_binary'] !== '') {
                $browsershot->setNpmBinary($config['npm_binary']);
            }
            if (is_string($config['chrome_path'] ?? null) && $config['chrome_path'] !== '') {
                $browsershot->setChromePath($config['chrome_path']);
            }
            if (is_string($config['node_modules'] ?? null) && $config['node_modules'] !== '') {
                $browsershot->setNodeModulePath($config['node_modules']);
            }

            return $browsershot->pdf();
        } catch (Throwable $e) {
            throw new PdfRenderFailed(PdfRenderFailed::RENDERER_ERROR, $e::class, $e);
        }
    }

    /**
     * The print HTML for a document. Public so the templates can be tested
     * without a browser (escaping, redaction, totals) — the same HTML is
     * what Chromium prints.
     *
     * @throws PdfRenderFailed
     */
    public static function html(PdfDocument $document): string
    {
        $template = $document->template();
        if (! in_array($template, self::TEMPLATES, true) || ! View::exists("documents.{$template}")) {
            throw new PdfRenderFailed(PdfRenderFailed::UNKNOWN_TEMPLATE, $template);
        }

        return View::make("documents.{$template}", ['doc' => $document->toArray()])->render();
    }
}
