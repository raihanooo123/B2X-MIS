<?php

namespace App\Domain\Ordering\BulkEntry;

use App\Domain\Pricing\BulkPriceResolver;
use App\Models\Pack;
use App\Models\Sku;
use App\Models\StockLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 05.1 §7, §14.1 — turns raw entry rows into the reconciliation preview,
 * without touching the basket or reserving anything.
 *
 *   match    SKU codes case-insensitively (canonical codes unchanged); two
 *            SKUs differing only by case are ambiguous; not-found rows get
 *            close-match suggestions;
 *   pack     the named pack, or the SKU's default sell pack — none is an
 *            error, never a guess;
 *   merge    duplicate SKU+pack rows into one (different packs stay apart),
 *            then validate the merged total;
 *   rules    MOQ, case increment and maximum are checked on the merged
 *            base quantity. A fix is a *suggestion* the buyer must accept,
 *            never applied silently;
 *   stock    a stock-tracked, non-backorder SKU without enough available
 *            is flagged `short` (still selectable; checkout decides);
 *   version  each row carries a preview_version over everything that would
 *            change the answer — SKU status and rules, pack mapping, list
 *            price and stock sufficiency — so confirmation can detect drift.
 *
 * A fixed number of queries whatever the row count (05.1 §14.3).
 * Rows carry internal sku_id/pack_id for the server; browser DTOs redact
 * them (BulkEntryImports::dto).
 */
final class Reconciler
{
    /** Outcomes the buyer may select for the basket. */
    public const SELECTABLE = ['ok', 'adjust', 'short'];

    private const MAX_BASE_QTY = 2147483647;

    public function __construct(private readonly BulkPriceResolver $prices = new BulkPriceResolver) {}

    /**
     * @param  list<array<string, mixed>>  $raw  EntryParser rows, or pre-resolved rows with sku_id/pack_id (saved list, reorder)
     * @return list<array<string, mixed>>
     */
    public function reconcile(array $raw, ?int $companyId): array
    {
        $codes = [];
        $skuIds = [];
        foreach ($raw as $row) {
            if (is_int($row['sku_id'] ?? null)) {
                $skuIds[] = $row['sku_id'];
            } elseif (($row['error_code'] ?? null) === null && is_string($row['sku_code'] ?? null)) {
                $codes[] = mb_strtolower($row['sku_code']);
            }
        }

        $byCode = $codes === [] ? collect() : Sku::query()->with('product:id,name')
            ->whereIn(DB::raw('lower(sku_code)'), array_values(array_unique($codes)))->get()
            ->groupBy(fn (Sku $s): string => mb_strtolower($s->sku_code));
        $byId = $skuIds === [] ? collect() : Sku::query()->with('product:id,name')->whereKey(array_values(array_unique($skuIds)))->get()->keyBy('id');

        $allSkus = $byCode->flatten(1)->merge($byId->values())->unique('id')->keyBy('id');
        $packs = $allSkus->isEmpty() ? collect() : Pack::query()->whereIn('sku_id', $allSkus->keys())->get()->groupBy('sku_id');

        // Pass 1: match each row to a SKU and pack.
        $rows = [];
        foreach ($raw as $row) {
            $rows[] = $this->match($row, $byCode, $byId, $packs);
        }

        $rows = $this->suggestions($rows);
        $rows = $this->merge($rows);

        // Pass 2: rules, stock and version on the merged rows.
        $matched = array_values(array_unique(array_filter(array_column($rows, 'sku_id'), 'is_int')));
        $available = $matched === [] ? collect() : StockLevel::query()->whereIn('sku_id', $matched)
            ->selectRaw('sku_id, COALESCE(SUM(available_base_qty), 0) AS available')->groupBy('sku_id')->pluck('available', 'sku_id');
        $resolution = $matched === [] ? null : $this->prices->resolveMany($matched, $companyId, 1, 'GB');

        foreach ($rows as &$row) {
            if (! is_int($row['sku_id']) || ! in_array($row['outcome'], ['ok'], true)) {
                continue;
            }
            /** @var Sku $sku */
            $sku = $allSkus->get($row['sku_id']);
            $this->rules($row, $sku);
            if ($row['outcome'] === 'ok' && $sku->is_stock_tracked && ! $sku->allow_backorder
                && (int) ($available[$sku->id] ?? 0) < $row['base_qty']) {
                $row['outcome'] = 'short';
                $row['error_code'] = 'insufficient_stock';
            }
            $price = $resolution?->resolved[$sku->id] ?? null;
            if ($price === null && $row['outcome'] !== 'invalid') {
                $row['outcome'] = 'inactive';
                $row['error_code'] = 'not_purchasable';
            }
            $row['preview_version'] = self::version($sku, $row, $price?->unitPriceE4, $row['outcome'] === 'short');
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Collection<int, Sku>>  $byCode
     * @param  Collection<int, Sku>  $byId
     * @param  Collection<int, Collection<int, Pack>>  $packs
     * @return array<string, mixed>
     */
    private function match(array $row, Collection $byCode, Collection $byId, Collection $packs): array
    {
        $out = [
            'row_no' => (int) $row['row_no'],
            'original_input' => (string) ($row['input'] ?? ''),
            'sku_id' => null,
            'sku_public_id' => null,
            'pack_id' => null,
            'sku_code' => is_string($row['sku_code'] ?? null) ? $row['sku_code'] : null,
            'name' => null,
            'pack_code' => is_string($row['pack_code'] ?? null) ? $row['pack_code'] : null,
            'pack_label' => null,
            'pack_qty' => is_int($row['pack_qty'] ?? null) ? $row['pack_qty'] : null,
            'pack_base_units' => null,
            'base_qty' => null,
            'outcome' => 'invalid',
            'error_code' => $row['error_code'] ?? null,
            'suggested_pack_qty' => null,
            'accepted_adjustment' => false,
            'merged_row_nos' => [],
            'suggestions' => [],
            'status' => null,
            'preview_version' => null,
        ];
        if ($out['error_code'] !== null) {
            return $out;
        }

        if (is_int($row['sku_id'] ?? null)) {
            $sku = $byId->get($row['sku_id']);
        } else {
            $candidates = $byCode->get(mb_strtolower((string) $out['sku_code']), collect());
            if ($candidates->count() > 1) {
                return [...$out, 'outcome' => 'ambiguous', 'error_code' => 'ambiguous_sku'];
            }
            $sku = $candidates->first();
        }
        if (! $sku instanceof Sku || $sku->trashed()) {
            return [...$out, 'outcome' => 'not_found', 'error_code' => 'sku_not_found'];
        }

        $out = [...$out, 'sku_id' => $sku->id, 'sku_public_id' => $sku->public_id, 'sku_code' => $sku->sku_code, 'name' => $sku->product->name ?? $sku->sku_code, 'status' => $sku->status];
        if ($sku->status !== 'active') {
            return [...$out, 'outcome' => 'inactive', 'error_code' => 'sku_'.$sku->status];
        }

        $skuPacks = $packs->get($sku->id, collect())->filter(fn (Pack $p): bool => $p->is_sellable);
        $pack = match (true) {
            is_int($row['pack_id'] ?? null) => $skuPacks->firstWhere('id', $row['pack_id']),
            $out['pack_code'] !== null => $skuPacks->first(fn (Pack $p): bool => mb_strtolower($p->code) === mb_strtolower((string) $out['pack_code'])),
            default => $skuPacks->firstWhere('is_default_sell', true),
        };
        if (! $pack instanceof Pack) {
            $code = is_int($row['pack_id'] ?? null) || $out['pack_code'] !== null ? 'pack_not_available' : 'no_default_pack';

            return [...$out, 'outcome' => 'no_pack', 'error_code' => $code];
        }

        $packQty = $out['pack_qty'] ?? 1;

        return [...$out, 'pack_id' => $pack->id, 'pack_code' => $pack->code, 'pack_label' => $pack->label, 'pack_qty' => $packQty,
            'pack_base_units' => $pack->base_units, 'base_qty' => $packQty * $pack->base_units, 'outcome' => 'ok', 'error_code' => null];
    }

    /**
     * Duplicate SKU+pack rows merge into the first, the merge shown.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function merge(array $rows): array
    {
        $first = [];
        $out = [];
        foreach ($rows as $row) {
            if ($row['outcome'] !== 'ok') {
                $out[] = $row;

                continue;
            }
            $key = "{$row['sku_id']}:{$row['pack_id']}";
            if (! isset($first[$key])) {
                $first[$key] = count($out);
                $out[] = $row;

                continue;
            }
            $target = &$out[$first[$key]];
            $target['pack_qty'] += $row['pack_qty'];
            $target['base_qty'] = $target['pack_qty'] * $target['pack_base_units'];
            $target['merged_row_nos'][] = $row['row_no'];
            if ($target['pack_qty'] > EntryParser::MAX_PACK_QTY || $target['base_qty'] > self::MAX_BASE_QTY) {
                $target['outcome'] = 'invalid';
                $target['error_code'] = 'quantity_too_large';
            }
            unset($target);
        }

        return $out;
    }

    /**
     * MOQ, case increment and maximum on the merged base quantity; a fix is
     * suggested in whole packs, never applied.
     *
     * @param  array<string, mixed>  $row
     */
    private function rules(array &$row, Sku $sku): void
    {
        $units = (int) $row['pack_base_units'];
        $base = (int) $row['base_qty'];
        $increment = max(1, $sku->order_increment_base_qty);
        $min = max(1, $sku->moq_base_qty);
        $max = $sku->max_order_base_qty;

        $valid = fn (int $packs): bool => $packs * $units >= $min && ($packs * $units) % $increment === 0 && ($max === null || $packs * $units <= $max);
        if ($valid((int) $row['pack_qty'])) {
            return;
        }

        $suggested = null;
        if ($max !== null && $base > $max) {
            for ($p = intdiv($max, $units); $p >= 1; $p--) {
                if ($valid($p)) {
                    $suggested = $p;
                    break;
                }
            }
        } else {
            // The next whole number of packs that meets the minimum and the increment.
            $start = max((int) $row['pack_qty'], intdiv($min + $units - 1, $units));
            for ($p = $start; $p <= $start + $increment; $p++) {
                if ($valid($p)) {
                    $suggested = $p;
                    break;
                }
            }
        }

        $reason = match (true) {
            $max !== null && $base > $max => 'above_maximum',
            $base < $min => 'below_minimum',
            default => 'not_a_case_multiple',
        };
        if ($suggested === null) {
            $row['outcome'] = 'invalid';
            $row['error_code'] = $reason;

            return;
        }
        $row['outcome'] = 'adjust';
        $row['error_code'] = $reason;
        $row['suggested_pack_qty'] = $suggested;
    }

    /**
     * Up to three close SKU codes for each not-found row, from one query.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function suggestions(array $rows): array
    {
        $prefixes = [];
        foreach ($rows as $row) {
            if ($row['outcome'] === 'not_found' && is_string($row['sku_code']) && mb_strlen($row['sku_code']) >= 3) {
                $prefixes[] = mb_strtolower(mb_substr($row['sku_code'], 0, 3));
            }
        }
        if ($prefixes === []) {
            return $rows;
        }

        $candidates = Sku::query()->where('status', 'active')
            ->where(function ($q) use ($prefixes): void {
                foreach (array_unique($prefixes) as $prefix) {
                    $q->orWhereRaw('lower(sku_code) LIKE ?', [addcslashes($prefix, '%_\\').'%']);
                }
            })->limit(2000)->pluck('sku_code')->all();

        foreach ($rows as &$row) {
            if ($row['outcome'] !== 'not_found' || ! is_string($row['sku_code'])) {
                continue;
            }
            $needle = mb_strtolower($row['sku_code']);
            $scored = [];
            foreach ($candidates as $code) {
                $scored[$code] = levenshtein($needle, mb_strtolower($code));
            }
            asort($scored);
            $row['suggestions'] = array_slice(array_keys(array_filter($scored, fn (int $d): bool => $d <= 3)), 0, 3);
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function version(Sku $sku, array $row, ?int $unitPriceE4, bool $short): string
    {
        return hash('sha256', (string) json_encode([
            $sku->id, $sku->status, $sku->moq_base_qty, $sku->order_increment_base_qty, $sku->max_order_base_qty,
            $row['pack_id'], $row['pack_base_units'], $unitPriceE4, $short,
        ]));
    }
}
