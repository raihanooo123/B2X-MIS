{{--
    05.17 §5 — the shared print layout for archived PDFs (invoice, credit note,
    statement). Blade is approved for printable documents only (CLAUDE.md);
    it receives an explicit payload array, never a model.

    Safety: every value is escaped by {{ }}; the CSP below lets Chromium load
    nothing but this inline CSS and the embedded font, so no network or file
    fetch can happen; there is no script. Layout: A4, table headings repeat on
    every page, totals and address blocks never split across pages.
--}}
@php
    $fontPath = public_path('fonts/figtree/figtree-latin-wght.woff2');
    $font = is_file($fontPath) ? base64_encode((string) file_get_contents($fontPath)) : null;
@endphp
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src data:">
<title>{{ $doc['title'] ?? 'Document' }} {{ $doc['number'] ?? '' }}</title>
<style>
@if ($font)
@font-face { font-family: 'Figtree'; font-style: normal; font-weight: 400 800; src: url(data:font/woff2;base64,{{ $font }}) format('woff2'); }
@endif
@page { size: A4; margin: 14mm 12mm 16mm 12mm; }
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; font-family: 'Figtree', 'Helvetica Neue', Arial, sans-serif; font-size: 9.5pt; line-height: 1.45; color: #111827; }
h1 { font-size: 20pt; margin: 0 0 2mm; letter-spacing: -0.01em; }
h2 { font-size: 11pt; margin: 6mm 0 2mm; }
.muted { color: #4b5563; }
.mono { font-family: 'Menlo', 'Consolas', monospace; font-size: 8.5pt; }
.top { display: flex; justify-content: space-between; gap: 10mm; align-items: flex-start; border-bottom: 2px solid #111827; padding-bottom: 5mm; }
.parties { display: flex; gap: 8mm; margin-top: 6mm; }
.parties > div { flex: 1; min-width: 0; overflow-wrap: anywhere; break-inside: avoid; }
.label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #4b5563; margin-bottom: 1mm; font-weight: 600; }
.meta { width: auto; border-collapse: collapse; }
.meta td { padding: 0.6mm 0 0.6mm 6mm; vertical-align: top; }
.meta td:first-child { color: #4b5563; padding-left: 0; }
table.lines { width: 100%; border-collapse: collapse; margin-top: 4mm; }
table.lines thead { display: table-header-group; }
table.lines th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.05em; color: #4b5563; border-bottom: 1px solid #9ca3af; padding: 2mm 2mm; }
table.lines td { padding: 2mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; overflow-wrap: anywhere; }
table.lines tr { break-inside: avoid; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.totals { margin-left: auto; width: 80mm; margin-top: 5mm; border-collapse: collapse; break-inside: avoid; }
.totals td { padding: 1.2mm 0; }
.totals tr.grand td { border-top: 2px solid #111827; font-weight: 700; font-size: 11pt; padding-top: 2mm; }
.box { border: 1px solid #d1d5db; border-radius: 2mm; padding: 3mm 4mm; break-inside: avoid; }
.grid { display: flex; gap: 4mm; flex-wrap: wrap; }
.grid > .box { flex: 1 1 40mm; }
.footer { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #d1d5db; font-size: 8pt; color: #4b5563; break-inside: avoid; }
.footer p { margin: 0 0 1mm; }
</style>
</head>
<body>
@yield('document')
</body>
</html>
