<?php

namespace App\Domain\Ordering\BulkEntry;

use App\Models\BulkEntryImport;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\SavedList;
use App\Models\SavedListLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 05.1 §7.3, §14.1 — saved lists and reorder.
 *
 * Lists are company-shared, named 1–80 characters (names need not be
 * unique), at most 500 lines, and hold SKU, pack and quantity intent only —
 * never a price. Every edit names the version it was made against; a stale
 * version is refused (409) so two buyers never overwrite each other.
 *
 * Using a list, or reordering a past order, never places anything: both
 * land on the same reconciliation preview as a paste. Reorder copies what
 * was ordered minus what was cancelled; returns do not change the original
 * intent, and old prices are never applied.
 */
final class SavedLists
{
    public const MAX_LINES = 500;

    public const PAGE_SIZE = 50;

    public const SORTS = ['name' => ['name', 'asc'], 'updated' => ['updated_at', 'desc']];

    public function __construct(private readonly BulkEntryImports $imports = new BulkEntryImports) {}

    /**
     * Keyset page: name ASC, id ASC (saved_lists_company_name_idx) or
     * updated_at DESC, id DESC (saved_lists_updated_cursor_idx).
     *
     * @param  array<string, int|string|null>|null  $after
     * @return array{rows: list<array<string, mixed>>, next: array<string, int|string>|null}
     */
    public function page(int $companyId, string $sort, ?string $q, ?array $after): array
    {
        [$column, $direction] = self::SORTS[$sort] ?? self::SORTS['name'];
        $query = SavedList::query()->where('company_id', $companyId)->withCount('lines')
            ->with('createdBy:id,first_name,last_name')->orderBy($column, $direction)->orderBy('id', $direction);
        if ($q !== null) {
            $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }
        if ($after !== null) {
            $operator = $direction === 'desc' ? '<' : '>';
            $query->whereRaw("({$column}, id) {$operator} (?, ?)", [$after['v'], $after['id']]);
        }

        $rows = $query->limit(self::PAGE_SIZE + 1)->get();
        $more = $rows->count() > self::PAGE_SIZE;
        $rows = $rows->take(self::PAGE_SIZE);
        $last = $rows->last();

        return [
            'rows' => array_values($rows->map(fn (SavedList $l): array => $this->summary($l))->all()),
            'next' => $more && $last !== null ? ['v' => (string) $last->getRawOriginal($column), 'id' => $last->id] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(SavedList $list): array
    {
        $lines = SavedListLine::query()->where('saved_list_id', $list->id)->with(['sku:id,public_id,sku_code,status,product_id', 'sku.product:id,name', 'pack:id,code,label,is_sellable'])
            ->orderBy('position')->orderBy('id')->get();

        return [
            ...$this->summary($list->loadCount('lines')->load('createdBy:id,first_name,last_name')),
            'lines' => array_values($lines->map(fn (SavedListLine $l): array => [
                'sku_id' => $l->sku->public_id ?? null,
                'sku_code' => $l->sku->sku_code ?? '',
                'name' => $l->sku?->product->name ?? '',
                'sku_status' => $l->sku->status ?? 'archived',
                'pack_code' => $l->pack->code ?? '',
                'pack_label' => $l->pack->label ?? '',
                'pack_sellable' => (bool) ($l->pack->is_sellable ?? false),
                'pack_qty' => $l->pack_qty,
                'pack_base_units' => $l->pack_base_units,
                'base_qty' => $l->base_qty,
            ])->all()),
        ];
    }

    /**
     * A new list: empty, or a copy of the buyer's current basket.
     *
     * @throws ImportRefused
     */
    public function create(Company $company, User $user, string $name, ?Cart $fromCart = null): SavedList
    {
        $name = self::name($name);

        return DB::transaction(function () use ($company, $user, $name, $fromCart): SavedList {
            $list = SavedList::query()->create([
                'company_id' => $company->id,
                'name' => $name,
                'created_by_user_id' => $user->id,
                'source' => $fromCart === null ? 'manual' : 'cart',
            ]);
            if ($fromCart !== null) {
                $lines = DB::table('cart_lines')->where('cart_id', $fromCart->id)->orderBy('id')->get(['sku_id', 'pack_id', 'pack_qty']);
                if ($lines->count() > self::MAX_LINES) {
                    throw new ImportRefused('too_many_lines', 'A list can hold at most '.self::MAX_LINES.' lines.', 422);
                }
                $this->writeLines($list, array_values($lines->map(fn (object $l): array => ['sku_id' => (int) $l->sku_id, 'pack_id' => (int) $l->pack_id, 'pack_qty' => (int) $l->pack_qty])->all()));
            }

            return $list;
        });
    }

    /**
     * Rename and/or replace the lines, against the version the buyer saw.
     *
     * @param  list<array{sku_id: string, pack_code: string, pack_qty: int}>|null  $lines  public SKU ids
     *
     * @throws ImportRefused
     */
    public function update(SavedList $list, int $version, ?string $name, ?array $lines): SavedList
    {
        return DB::transaction(function () use ($list, $version, $name, $lines): SavedList {
            $locked = SavedList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $version) {
                throw new ImportRefused('list_changed', 'Someone else changed this list. Reload it to see their changes, then try again.', 409);
            }
            if ($name !== null) {
                $locked->name = self::name($name);
            }
            if ($lines !== null) {
                if (count($lines) > self::MAX_LINES) {
                    throw new ImportRefused('too_many_lines', 'A list can hold at most '.self::MAX_LINES.' lines.', 422);
                }
                $skus = Sku::query()->whereIn('public_id', array_column($lines, 'sku_id'))->get(['id', 'public_id'])->keyBy('public_id');
                $packs = Pack::query()->whereIn('sku_id', $skus->pluck('id'))->get(['id', 'sku_id', 'code']);
                $resolved = [];
                foreach ($lines as $i => $line) {
                    $sku = $skus->get($line['sku_id']);
                    $pack = $sku === null ? null : $packs->first(fn (Pack $p): bool => $p->sku_id === $sku->id && mb_strtolower($p->code) === mb_strtolower($line['pack_code']));
                    if ($pack === null || $line['pack_qty'] < 1 || $line['pack_qty'] > EntryParser::MAX_PACK_QTY) {
                        throw new ImportRefused('invalid_line', 'Line '.($i + 1).' is not a product, pack and quantity this list can hold.', 422);
                    }
                    $resolved[] = ['sku_id' => $sku->id, 'pack_id' => $pack->id, 'pack_qty' => $line['pack_qty']];
                }
                SavedListLine::query()->where('saved_list_id', $locked->id)->delete();
                $this->writeLines($locked, $resolved);
            }
            $locked->version++;
            $locked->save();

            return $locked;
        });
    }

    /** @throws ImportRefused */
    public function delete(SavedList $list, int $version): void
    {
        DB::transaction(function () use ($list, $version): void {
            $locked = SavedList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $version) {
                throw new ImportRefused('list_changed', 'Someone else changed this list. Reload it before deleting.', 409);
            }
            $locked->delete();
        });
    }

    /** A list as a reconciliation preview. Adds nothing to the basket. */
    public function preview(SavedList $list, Company $company, User $user): BulkEntryImport
    {
        $raw = [];
        foreach (SavedListLine::query()->where('saved_list_id', $list->id)->orderBy('position')->orderBy('id')->get() as $i => $line) {
            $raw[] = ['row_no' => $i + 1, 'input' => 'Saved list line '.($i + 1), 'sku_id' => $line->sku_id, 'pack_id' => $line->pack_id,
                'sku_code' => null, 'pack_code' => null, 'pack_qty' => $line->pack_qty, 'error_code' => null];
        }

        return $this->imports->stage($company, $user, 'saved_list', $raw, hash('sha256', "saved_list:{$list->id}:{$list->version}"));
    }

    /**
     * A past order as a reconciliation preview: what was ordered minus what
     * was cancelled; discontinued or unavailable items are shown, not dropped.
     */
    public function reorder(Order $order, Company $company, User $user): BulkEntryImport
    {
        $raw = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->orderBy('id')->get() as $line) {
            $remainingBase = $line->base_qty - $line->cancelled_base_qty;
            $packs = intdiv($remainingBase, max(1, $line->pack_base_units));
            if ($packs < 1) {
                continue;
            }
            $raw[] = ['row_no' => $line->line_no, 'input' => "{$line->sku_code_snapshot} × {$packs} ({$line->pack_label_snapshot})", 'sku_id' => $line->sku_id,
                'pack_id' => $line->pack_id, 'sku_code' => $line->sku_code_snapshot, 'pack_code' => null, 'pack_qty' => $packs, 'error_code' => null];
        }

        return $this->imports->stage($company, $user, 'reorder', $raw, hash('sha256', "reorder:{$order->id}"));
    }

    /** @param list<array{sku_id: int, pack_id: int, pack_qty: int}> $lines */
    private function writeLines(SavedList $list, array $lines): void
    {
        $units = Pack::query()->whereKey(array_column($lines, 'pack_id'))->pluck('base_units', 'id');
        $merged = [];
        foreach ($lines as $line) {
            $key = "{$line['sku_id']}:{$line['pack_id']}";
            $merged[$key] = isset($merged[$key]) ? [...$merged[$key], 'pack_qty' => $merged[$key]['pack_qty'] + $line['pack_qty']] : $line;
        }
        $position = 0;
        foreach ($merged as $line) {
            $base = (int) $units[$line['pack_id']];
            SavedListLine::query()->create([
                'saved_list_id' => $list->id,
                'sku_id' => $line['sku_id'],
                'pack_id' => $line['pack_id'],
                'pack_qty' => $line['pack_qty'],
                'pack_base_units' => $base,
                'base_qty' => $line['pack_qty'] * $base,
                'position' => $position++,
            ]);
        }
    }

    /** @throws ImportRefused */
    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ImportRefused('invalid_name', 'Give the list a name of 1 to 80 characters.', 422);
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private function summary(SavedList $list): array
    {
        $by = $list->createdBy;

        return [
            'id' => $list->public_id,
            'name' => $list->name,
            'version' => $list->version,
            'source' => $list->source,
            'line_count' => (int) ($list->lines_count ?? 0),
            'created_by' => $by === null ? null : trim("{$by->first_name} {$by->last_name}"),
            'updated_at' => $list->updated_at->toIso8601ZuluString(),
        ];
    }
}
