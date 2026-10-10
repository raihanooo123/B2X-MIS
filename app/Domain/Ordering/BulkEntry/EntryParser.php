<?php

namespace App\Domain\Ordering\BulkEntry;

/**
 * 05.1 §7.1–§7.2, §14.1 — reads pasted lines or a CSV into raw entry rows,
 * nothing more: matching, packs, rules and merging are the Reconciler's.
 *
 * Paste: one item per line, code then optional quantity, separated by
 * comma, semicolon, tab or spaces. CSV: UTF-8 (optional BOM), quoted
 * fields, case-insensitive headers in any order — `sku_code`, `quantity`,
 * optional `pack_code`. Quantity is a number of packs; omitted means one
 * default pack. Blank rows are ignored; a malformed row is kept with its
 * input row number and an error, never silently dropped.
 *
 * Each raw row: row_no, input (bounded), sku_code, pack_code, pack_qty,
 * error_code.
 */
final class EntryParser
{
    public const MAX_ROWS = 5000;

    /** Packs per row; far above any real order, below integer overflow once multiplied. */
    public const MAX_PACK_QTY = 1_000_000;

    private const INPUT_MAX = 200;

    /**
     * @return list<array{row_no: int, input: string, sku_code: ?string, pack_code: ?string, pack_qty: ?int, error_code: ?string}>
     *
     * @throws EntryRejected when there are more rows than allowed
     */
    public function paste(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $parts = preg_split('/[\s,;]+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $code = $parts[0] ?? null;
            $qty = $parts[1] ?? null;
            $error = count($parts) > 2 ? 'malformed' : null;

            $rows[] = $this->row($i + 1, $trimmed, $code, null, $qty, $error);
            $this->guardCount($rows);
        }

        return $rows;
    }

    /**
     * @return list<array{row_no: int, input: string, sku_code: ?string, pack_code: ?string, pack_qty: ?int, error_code: ?string}>
     *
     * @throws EntryRejected for a missing sku_code header, a non-UTF-8 file or too many rows
     */
    public function csv(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new EntryRejected('not_utf8', 'Save the file as UTF-8 CSV and upload it again.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new EntryRejected('unreadable', 'The file could not be read.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        $header = null;
        $rows = [];
        $recordNo = 0;
        while (($record = fgetcsv($stream, escape: '')) !== false) {
            $recordNo++;
            $cells = array_map(fn ($cell): string => trim((string) $cell), $record);
            if (implode('', $cells) === '') {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn (string $name): string => strtolower($name), $cells);
                if (! in_array('sku_code', $header, true)) {
                    fclose($stream);
                    throw new EntryRejected('missing_header', 'The first row must name the columns: sku_code, quantity and, optionally, pack_code.');
                }

                continue;
            }

            $value = function (string $column) use ($header, $cells): ?string {
                $index = array_search($column, $header, true);
                $cell = $index === false ? null : ($cells[$index] ?? null);

                return $cell === null || $cell === '' ? null : $cell;
            };

            $rows[] = $this->row($recordNo, implode(',', $cells), $value('sku_code'), $value('pack_code'), $value('quantity'), count($cells) > count($header) ? 'malformed' : null);
            $this->guardCount($rows);
        }
        fclose($stream);

        if ($header === null) {
            throw new EntryRejected('empty', 'The file has no rows.');
        }

        return $rows;
    }

    /** @return array{row_no: int, input: string, sku_code: ?string, pack_code: ?string, pack_qty: ?int, error_code: ?string} */
    private function row(int $rowNo, string $input, ?string $code, ?string $packCode, ?string $qty, ?string $error): array
    {
        $packQty = null;
        if ($error === null) {
            if ($code === null || $code === '') {
                $error = 'missing_code';
            } elseif ($qty !== null) {
                [$packQty, $error] = self::quantity($qty);
            }
        }

        return [
            'row_no' => $rowNo,
            'input' => mb_substr($input, 0, self::INPUT_MAX),
            'sku_code' => $code === null ? null : mb_substr($code, 0, 64),
            'pack_code' => $packCode === null ? null : mb_substr($packCode, 0, 64),
            'pack_qty' => $packQty,
            'error_code' => $error,
        ];
    }

    /**
     * A whole positive number of packs, as text — never through a float.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function quantity(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $text) !== 1) {
            return [null, 'invalid_quantity'];
        }
        if (str_contains($text, '.') && preg_match('/\.0+$/', $text) !== 1) {
            return [null, 'fractional_quantity'];
        }
        $digits = ltrim(explode('.', $text)[0], '+');
        if (str_starts_with($digits, '-') || ltrim($digits, '0') === '') {
            return [null, 'non_positive_quantity'];
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) > 7 || (int) $digits > self::MAX_PACK_QTY) {
            return [null, 'quantity_too_large'];
        }

        return [(int) $digits, null];
    }

    /** @param list<array<string, mixed>> $rows */
    private function guardCount(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new EntryRejected('too_many_rows', 'A file or paste can have at most '.number_format(self::MAX_ROWS).' rows. Split it and import each part.');
        }
    }
}
