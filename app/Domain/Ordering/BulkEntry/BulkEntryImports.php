<?php

namespace App\Domain\Ordering\BulkEntry;

use App\Domain\Ordering\CartService;
use App\Jobs\ProcessBulkEntryImport;
use App\Models\BulkEntryImport;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Pack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 05.1 §14.1 — the staging contract for paste, CSV, saved-list and
 * reorder entry. A preview never changes the basket or reserves stock.
 *
 *   stage    store the raw rows (and a CSV privately), reconcile at once up
 *            to 500 rows, otherwise on the queue with the captured company
 *            and user — never a worker's ambient context;
 *   confirm  once only. Locks the import, then the cart; the cart must be
 *            the version the buyer previewed against and every selected row
 *            must still reconcile the same (price, stock, pack, rules) —
 *            otherwise the preview is refreshed and the call answers 409.
 *            Selected rows **merge** into the basket (existing + new);
 *            a suggested quantity is used only where explicitly accepted.
 *            A repeat confirm returns the saved result;
 *   expire   after 24 hours input and preview are purged (private CSV
 *            deleted); a minimal no-PII receipt is kept 7 days, then
 *            removed. Confirming an expired import adds nothing.
 */
final class BulkEntryImports
{
    public const SYNC_LIMIT = 500;

    public const TTL_HOURS = 24;

    public const RECEIPT_DAYS = 7;

    public const MAX_FILE_KB = 5120;

    public function __construct(
        private readonly Reconciler $reconciler = new Reconciler,
        private readonly CartService $carts = new CartService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $raw
     */
    public function stage(Company $company, User $user, string $source, array $raw, string $inputSha256, ?string $privatePath = null): BulkEntryImport
    {
        $import = BulkEntryImport::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'tool' => 'order_pad',
            'source' => $source,
            'status' => BulkEntryImport::PENDING,
            'input_sha256' => $inputSha256,
            'private_storage_path' => $privatePath,
            // Raw rows until reconciled; replaced by the preview.
            'rows' => $raw,
            'created_at' => now(),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        if (count($raw) <= self::SYNC_LIMIT) {
            $this->process($import->id);
        } else {
            DB::afterCommit(fn () => ProcessBulkEntryImport::dispatch($import->id));
        }

        return $import->refresh();
    }

    /** Reconcile a staged import against its own captured company. */
    public function process(int $importId): void
    {
        $import = DB::transaction(function () use ($importId): ?BulkEntryImport {
            $locked = BulkEntryImport::query()->whereKey($importId)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, [BulkEntryImport::PENDING, BulkEntryImport::PROCESSING], true)) {
                return null;
            }
            $locked->forceFill(['status' => BulkEntryImport::PROCESSING])->save();

            return $locked;
        });
        if ($import === null) {
            return;
        }

        try {
            $rows = $this->reconciler->reconcile($import->rows, $import->company_id);
            DB::table('bulk_entry_imports')->where('id', $import->id)->where('status', BulkEntryImport::PROCESSING)->update([
                'rows' => json_encode($rows, JSON_THROW_ON_ERROR),
                'status' => BulkEntryImport::READY,
                'version' => DB::raw('version + 1'),
            ]);
        } catch (Throwable $e) {
            DB::table('bulk_entry_imports')->where('id', $import->id)->update(['status' => BulkEntryImport::FAILED]);
            throw $e;
        }
    }

    /**
     * A fingerprint of the basket's lines, so the buyer's confirmation is
     * checked against the basket they previewed (05.1 §14.1).
     */
    public function cartVersion(Cart $cart): string
    {
        $lines = DB::table('cart_lines')->where('cart_id', $cart->id)->orderBy('id')->get(['id', 'sku_id', 'pack_id', 'pack_qty']);

        return hash('sha256', (string) json_encode($lines->map(fn (object $l): array => [(int) $l->id, (int) $l->sku_id, (int) $l->pack_id, (int) $l->pack_qty])->all()));
    }

    /**
     * @param  list<int>  $rowNos  the rows to add
     * @param  list<int>  $acceptedAdjustments  rows whose suggested quantity the buyer accepted
     * @return array<string, mixed> the confirmed result
     *
     * @throws ImportRefused
     */
    public function confirm(BulkEntryImport $import, Cart $cart, int $version, array $rowNos, array $acceptedAdjustments, string $cartVersion): array
    {
        $outcome = DB::transaction(function () use ($import, $cart, $version, $rowNos, $acceptedAdjustments, $cartVersion): array {
            $locked = BulkEntryImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BulkEntryImport::CONFIRMED) {
                return ['result' => $locked->confirmed_result ?? []];
            }
            if ($locked->isExpired()) {
                throw new ImportRefused('import_expired', 'This import has expired. Paste or upload it again.', 409);
            }
            if ($locked->status !== BulkEntryImport::READY) {
                throw new ImportRefused('import_not_ready', 'This import is still being checked.', 409);
            }
            if ($locked->version !== $version) {
                throw new ImportRefused('preview_changed', 'The preview has changed. Check it and confirm again.', 409);
            }

            $byNo = [];
            foreach ($locked->rows as $i => $row) {
                $byNo[(int) $row['row_no']] = $i;
            }
            $selected = array_values(array_unique($rowNos));
            if ($selected === []) {
                throw new ImportRefused('nothing_selected', 'Select at least one row to add.', 422);
            }
            foreach ($selected as $no) {
                $row = isset($byNo[$no]) ? $locked->rows[$byNo[$no]] : null;
                if ($row === null || ! in_array($row['outcome'], Reconciler::SELECTABLE, true)) {
                    throw new ImportRefused('row_not_selectable', "Row {$no} cannot be added.", 422);
                }
                if ($row['outcome'] === 'adjust' && ! in_array($no, $acceptedAdjustments, true)) {
                    throw new ImportRefused('adjustment_not_accepted', "Row {$no} needs a different quantity. Accept the suggested quantity to add it.", 422);
                }
            }

            $lockedCart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($this->cartVersion($lockedCart), $cartVersion)) {
                throw new ImportRefused('cart_changed', 'Your basket changed since this preview. Check the preview and confirm again.', 409);
            }

            // Re-check every selected row now, under the cart lock.
            $recheck = [];
            foreach ($selected as $no) {
                $row = $locked->rows[$byNo[$no]];
                $recheck[] = ['row_no' => $no, 'input' => $row['original_input'], 'sku_id' => $row['sku_id'], 'pack_id' => $row['pack_id'],
                    'sku_code' => $row['sku_code'], 'pack_code' => $row['pack_code'], 'pack_qty' => $row['pack_qty'], 'error_code' => null];
            }
            $fresh = $this->reconciler->reconcile($recheck, $locked->company_id);
            $changed = false;
            $rows = $locked->rows;
            foreach ($fresh as $f) {
                $i = $byNo[$f['row_no']];
                if ($f['preview_version'] !== $rows[$i]['preview_version'] || $f['outcome'] !== $rows[$i]['outcome']) {
                    $changed = true;
                    $rows[$i] = [...$f, 'merged_row_nos' => $rows[$i]['merged_row_nos']];
                }
            }
            if ($changed) {
                $locked->forceFill(['rows' => $rows, 'version' => $locked->version + 1])->save();

                return ['refreshed' => true];
            }

            $writes = [];
            foreach ($selected as $no) {
                $row = $rows[$byNo[$no]];
                $packQty = $row['outcome'] === 'adjust' ? (int) $row['suggested_pack_qty'] : (int) $row['pack_qty'];
                $writes[] = ['sku_id' => (int) $row['sku_id'], 'pack_id' => (int) $row['pack_id'], 'pack_qty' => $packQty, 'row_no' => $no];
                $rows[$byNo[$no]]['accepted_adjustment'] = $row['outcome'] === 'adjust';
            }
            usort($writes, fn (array $a, array $b): int => [$a['sku_id'], $a['pack_id']] <=> [$b['sku_id'], $b['pack_id']]);
            $packs = Pack::query()->whereKey(array_column($writes, 'pack_id'))->get()->keyBy('id');
            foreach ($writes as $write) {
                $pack = $packs->get($write['pack_id']);
                if (! $pack instanceof Pack) {
                    throw new ImportRefused('preview_changed', 'A pack on this list no longer exists. Check the preview again.', 409);
                }
                $this->carts->addLine($lockedCart, $pack, $write['pack_qty']);
            }

            $result = [
                'cart_version' => $this->cartVersion($lockedCart),
                'row_nos' => array_column($writes, 'row_no'),
                'lines' => count($writes),
            ];
            $locked->forceFill([
                'rows' => $rows,
                'status' => BulkEntryImport::CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_result' => $result,
            ])->save();

            return ['result' => $result];
        });

        if (isset($outcome['refreshed'])) {
            throw new ImportRefused('preview_changed', 'Prices, stock or product details changed since the preview. Check the updated rows and confirm again.', 409);
        }

        return $outcome['result'];
    }

    /**
     * The browser's view: public ULIDs only, no internal ids, no costs.
     *
     * @return array<string, mixed>
     */
    public function dto(BulkEntryImport $import): array
    {
        $ready = in_array($import->status, [BulkEntryImport::READY, BulkEntryImport::CONFIRMED], true);
        $rows = $ready ? array_map(fn (array $r): array => [
            'row_no' => $r['row_no'],
            'input' => $r['original_input'],
            'sku_id' => $r['sku_public_id'],
            'sku_code' => $r['sku_code'],
            'name' => $r['name'],
            'pack_code' => $r['pack_code'],
            'pack_label' => $r['pack_label'],
            'pack_qty' => $r['pack_qty'],
            'pack_base_units' => $r['pack_base_units'],
            'base_qty' => $r['base_qty'],
            'outcome' => $r['outcome'],
            'error_code' => $r['error_code'],
            'problem' => ImportMessages::problem($r),
            'suggested_pack_qty' => $r['suggested_pack_qty'],
            'accepted_adjustment' => $r['accepted_adjustment'],
            'merged_row_nos' => $r['merged_row_nos'],
            'suggestions' => $r['suggestions'],
            'status' => $r['status'],
        ], $import->rows) : [];

        $counts = array_count_values(array_column($rows, 'outcome'));

        return [
            'id' => $import->public_id,
            'source' => $import->source,
            'status' => $import->isExpired() && $import->status !== BulkEntryImport::CONFIRMED ? BulkEntryImport::EXPIRED : $import->status,
            'version' => $import->version,
            'created_at' => $import->created_at->toIso8601ZuluString(),
            'expires_at' => $import->expires_at->toIso8601ZuluString(),
            'row_count' => $ready ? count($rows) : count($import->rows),
            'counts' => $counts,
            'rows' => $rows,
            'confirmed' => $import->confirmed_result === null ? null : [
                'lines' => $import->confirmed_result['lines'] ?? 0,
                'row_nos' => $import->confirmed_result['row_nos'] ?? [],
            ],
        ];
    }

    /**
     * The rows that could not be added, as CSV for the buyer to fix their
     * own file. Cells a spreadsheet would run as a formula are neutralised.
     */
    public function rejectionsCsv(BulkEntryImport $import): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }
        fputcsv($stream, ['row', 'input', 'sku_code', 'problem'], escape: '');
        foreach ($import->rows as $row) {
            if (in_array($row['outcome'] ?? null, Reconciler::SELECTABLE, true)) {
                continue;
            }
            fputcsv($stream, array_map(self::safeCell(...), [
                (string) $row['row_no'], (string) ($row['original_input'] ?? ''), (string) ($row['sku_code'] ?? ''), ImportMessages::problem($row),
            ]), escape: '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** Leading =, +, -, @, tab or CR would make a spreadsheet evaluate the cell. */
    public static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** Purge expired input and previews; drop receipts after 7 days. @return int rows purged */
    public function purgeExpired(): int
    {
        $purged = 0;
        BulkEntryImport::query()->where('expires_at', '<', now())->where('rows', '<>', '[]')
            ->orderBy('id')->chunkById(200, function ($imports) use (&$purged): void {
                foreach ($imports as $import) {
                    if (is_string($import->private_storage_path)) {
                        Storage::disk((string) config('documents.disk'))->delete($import->private_storage_path);
                    }
                    $import->forceFill([
                        'rows' => [],
                        'private_storage_path' => null,
                        'status' => $import->status === BulkEntryImport::CONFIRMED ? BulkEntryImport::CONFIRMED : BulkEntryImport::EXPIRED,
                    ])->save();
                    $purged++;
                }
            });
        BulkEntryImport::query()->where('expires_at', '<', now()->subDays(self::RECEIPT_DAYS))->delete();

        return $purged;
    }
}
