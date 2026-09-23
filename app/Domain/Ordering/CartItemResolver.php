<?php

namespace App\Domain\Ordering;

use App\Domain\Ordering\Exceptions\CartItemRejectedException;
use App\Models\Pack;
use App\Models\Sku;
use Illuminate\Support\Collection;

/**
 * Turns what a client sends — a SKU ULID, an optional pack code, a pack
 * quantity and optionally the base quantity it believes that is — into
 * the Pack that CartService works with.
 *
 * Packs are addressed by `code`, unique per SKU (`packs_sku_code_uq`),
 * because `packs` has no `public_id` (02 §5.6) and 06 §2 forbids
 * exposing `packs.id`. `code` is already what 05.1 §7.2's CSV
 * `pack_code` column uses. Omitted → the SKU's default sell pack (05.1
 * §7.1: "quantity omitted → 1 pack, default pack").
 *
 * Rejected here, before anything is written (05.1 §6):
 *   - unknown SKU, or a SKU that is not `active` ("inactive SKUs
 *     excluded from results entirely")
 *   - unknown pack, or `is_sellable = false` ("non-sellable packs never
 *     offered")
 *   - `base_qty` disagreeing with `pack_qty × base_units` (06 §3.2, the
 *     boundary copy of `cart_lines_base_qty_chk`)
 *
 * MOQ / increment / max / stock are not rejected here — they are
 * flagged, not blocking, at entry (05.1 §6) and become checkout
 * blockers in CheckoutPreviewService.
 */
final class CartItemResolver
{
    /** `cart_lines.base_qty` is `integer`; reject before Postgres does. */
    private const MAX_BASE_QTY = 2147483647;

    /**
     * One item. See resolveMany() for the batched form.
     */
    public function resolve(string $skuPublicId, ?string $packCode, int $packQty, ?int $baseQty, string $field = ''): Pack
    {
        $skus = Sku::query()->where('public_id', $skuPublicId)->get()->keyBy('public_id');
        $packs = $this->packsFor($skus);

        return $this->resolveOne($skus, $packs, $skuPublicId, $packCode, $packQty, $baseQty, $field);
    }

    /**
     * Two queries regardless of item count. Every item is checked, and
     * every failure is returned in `rejections` rather than thrown, so a
     * bulk caller can report all of them at once. `packs` is keyed by
     * input index and holds only the items that passed.
     *
     * @param  list<array{sku_id: string, pack_code: ?string, pack_qty: int, base_qty: ?int}>  $items
     * @return array{packs: array<int, Pack>, rejections: list<CartItemRejectedException>}
     */
    public function resolveMany(array $items, string $fieldPrefix = 'lines'): array
    {
        $skus = Sku::query()->whereIn('public_id', array_unique(array_column($items, 'sku_id')))->get()->keyBy('public_id');
        $packs = $this->packsFor($skus);

        $resolved = [];
        $rejections = [];
        foreach ($items as $i => $item) {
            try {
                $resolved[$i] = $this->resolveOne($skus, $packs, $item['sku_id'], $item['pack_code'], $item['pack_qty'], $item['base_qty'], "{$fieldPrefix}.{$i}.");
            } catch (CartItemRejectedException $e) {
                $rejections[] = $e->atLine($i);
            }
        }

        return ['packs' => $resolved, 'rejections' => $rejections];
    }

    /**
     * A pack code for an existing line's SKU — PATCH changing pack.
     */
    public function packForSku(int $skuId, string $packCode, int $packQty, ?int $baseQty): Pack
    {
        $pack = Pack::query()->where('sku_id', $skuId)->where('code', $packCode)->first();

        if ($pack === null) {
            throw new CartItemRejectedException('pack_code', 'pack_not_found', "This item has no pack '{$packCode}'.", ['pack_code' => $packCode]);
        }

        return $this->checkPack($pack, $packQty, $baseQty, '');
    }

    /**
     * @param  Collection<string, Sku>  $skus
     * @return Collection<int|string, Collection<int, Pack>>
     */
    private function packsFor(Collection $skus): Collection
    {
        return Pack::query()->whereIn('sku_id', $skus->pluck('id'))->get()->toBase()->groupBy('sku_id');
    }

    /**
     * @param  Collection<string, Sku>  $skus
     * @param  Collection<int|string, Collection<int, Pack>>  $packsBySku
     */
    private function resolveOne(Collection $skus, Collection $packsBySku, string $skuPublicId, ?string $packCode, int $packQty, ?int $baseQty, string $field): Pack
    {
        $sku = $skus->get($skuPublicId);

        if ($sku === null) {
            throw new CartItemRejectedException("{$field}sku_id", 'sku_not_found', "No SKU found for id {$skuPublicId}.", ['sku_id' => $skuPublicId]);
        }

        if ($sku->status !== 'active') {
            throw new CartItemRejectedException("{$field}sku_id", 'not_purchasable', 'This item is not currently available to order.', ['sku_id' => $skuPublicId, 'status' => $sku->status]);
        }

        $packs = $packsBySku->get($sku->id) ?? new Collection;

        $pack = $packCode !== null
            ? $packs->firstWhere('code', $packCode)
            : ($packs->firstWhere('id', $sku->default_pack_id) ?? $packs->firstWhere('is_default_sell', true));

        if ($pack === null) {
            throw $packCode !== null
                ? new CartItemRejectedException("{$field}pack_code", 'pack_not_found', "This item has no pack '{$packCode}'.", ['sku_id' => $skuPublicId, 'pack_code' => $packCode])
                : new CartItemRejectedException("{$field}pack_code", 'pack_required', 'This item has no default pack; a pack_code is required.', ['sku_id' => $skuPublicId]);
        }

        return $this->checkPack($pack, $packQty, $baseQty, $field);
    }

    private function checkPack(Pack $pack, int $packQty, ?int $baseQty, string $field): Pack
    {
        if (! $pack->is_sellable) {
            throw new CartItemRejectedException("{$field}pack_code", 'pack_not_sellable', 'This pack size is not sold.', ['pack_code' => $pack->code]);
        }

        $expectedBaseQty = $packQty * $pack->base_units;

        if ($expectedBaseQty > self::MAX_BASE_QTY) {
            throw new CartItemRejectedException("{$field}pack_qty", 'quantity_too_large', 'This quantity is too large.', ['pack_qty' => $packQty, 'base_units' => $pack->base_units]);
        }

        if ($baseQty !== null && $baseQty !== $expectedBaseQty) {
            throw new CartItemRejectedException("{$field}base_qty", 'base_qty_mismatch', "base_qty must equal pack_qty × {$pack->base_units}.", [
                'pack_qty' => $packQty,
                'base_units' => $pack->base_units,
                'base_qty' => $baseQty,
                'expected_base_qty' => $expectedBaseQty,
            ]);
        }

        return $pack;
    }
}
