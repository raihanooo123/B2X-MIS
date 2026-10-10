<?php

/*
 * 05.17 §3, §5 — financial PDFs. Renderer A (approved 2026-10-05):
 * Puppeteer's pinned Chromium via spatie/browsershot, run from a queued
 * job, never from a request. `null` renders nothing and every render
 * fails with `renderer_unavailable` — it never counts as success.
 */
return [
    'renderer' => env('PDF_RENDERER', 'browsershot'),

    // Archived PDFs: private storage, served through the application only.
    'disk' => env('DOCUMENTS_DISK', env('FILESYSTEM_DISK', 'local')),

    'queue' => env('DOCUMENTS_QUEUE', 'documents'),

    // Layout version per document type. A new layout renders a new
    // document version; an archived version keeps the layout it had.
    'template_versions' => [
        'invoice' => 'invoice-2026-10',
        'credit_note' => 'credit-note-2026-10',
        'statement' => 'statement-2026-10',
    ],

    // What a rendered file must satisfy before it is archived.
    'limits' => [
        'max_bytes' => (int) env('PDF_MAX_BYTES', 20 * 1024 * 1024),
        'max_pages' => (int) env('PDF_MAX_PAGES', 500),
    ],

    'browsershot' => [
        // Empty: Browsershot finds node/npm on PATH and Puppeteer's own Chromium.
        'node_binary' => env('PDF_NODE_BINARY'),
        'npm_binary' => env('PDF_NPM_BINARY'),
        'chrome_path' => env('PDF_CHROME_PATH'),
        'node_modules' => env('PDF_NODE_MODULES', base_path('node_modules')),
        'timeout_seconds' => (int) env('PDF_TIMEOUT_SECONDS', 30),
    ],

    // Statements: at most this many months per request (05.17 §2).
    'statement_max_months' => 12,
];
