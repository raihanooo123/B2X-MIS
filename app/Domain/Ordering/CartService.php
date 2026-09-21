<?php

namespace App\Domain\Ordering;

use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Pack;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 05.1 §8.3 — pad state persists server-side in `carts`/`cart_lines`,
 * never browser storage. Cart lines hold `sku_id`, `pack_id` and
 * `base_qty`, "so a pack change is a line update rather than a delete
 * and re-add" — that sentence is this class's whole reason to exist:
 * `changePack()` below is an UPDATE of the existing row's `pack_id`, not
 * a delete-then-insert, except in the one case where the target pack
 * already has its own line on the same cart (§10 edge case is silent on
 * this; merging the quantities is the only choice that doesn't either
 * lose stock the buyer already entered or violate
 * `cart_lines_cart_sku_pack_uq`).
 *
 * Deliberately NOT validated here: MOQ, order increment, max order,
 * stock availability (05.1 §6). That table is explicit that these are
 * "flagged"/"capped with a note" at entry, not blocking — server-side
 * authority for them belongs with whatever renders the pad (bulk-resolve
 * / stock-availability endpoints, 06 §9), not with cart CRUD. Checkout
 * itself re-validates purchasability via PriceResolver regardless.
 */
final class CartService
{
    /**
     * Adds `packQty` of `$pack` to the cart. If a line for this exact
     * (cart, sku, pack) already exists — `cart_lines_cart_sku_pack_uq` —
     * the quantity is added onto it rather than raising a duplicate-key
     * error, matching how a buyer expects "add 5 more" to behave.
     */
    public function addLine(Cart $cart, Pack $pack, int $packQty): CartLine
    {
        $this->assertPositiveQty($packQty);

        return DB::transaction(function () use ($cart, $pack, $packQty) {
            $existing = CartLine::query()
                ->where('cart_id', $cart->id)
                ->where('sku_id', $pack->sku_id)
                ->where('pack_id', $pack->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->setQuantity($existing, $existing->pack_qty + $packQty);
            }

            return CartLine::create([
                'cart_id' => $cart->id,
                'sku_id' => $pack->sku_id,
                'pack_id' => $pack->id,
                'pack_qty' => $packQty,
                'pack_base_units' => $pack->base_units,
                'base_qty' => $packQty * $pack->base_units,
            ]);
        });
    }

    /**
     * A pure quantity change — the line keeps its current pack.
     */
    public function updateQuantity(CartLine $line, int $packQty): CartLine
    {
        $this->assertPositiveQty($packQty);

        return $this->setQuantity($line, $packQty);
    }

    /**
     * §8.3: "a pack change is a line update rather than a delete and
     * re-add." `$newPack` must belong to the same SKU as the line —
     * switching SKU entirely is a different line, not a pack change.
     *
     * If the cart already has a separate line for (sku, $newPack) — the
     * buyer had both pack sizes in the basket and is now collapsing onto
     * one — the two are merged onto that existing line and this one is
     * removed, since both rows surviving would collide on
     * `cart_lines_cart_sku_pack_uq`.
     */
    public function changePack(CartLine $line, Pack $newPack, ?int $packQty = null): CartLine
    {
        if ($newPack->sku_id !== $line->sku_id) {
            throw new InvalidArgumentException(
                "Pack {$newPack->id} belongs to sku {$newPack->sku_id}, not the line's sku {$line->sku_id} — this is a different SKU, not a pack change."
            );
        }

        $packQty ??= $line->pack_qty;
        $this->assertPositiveQty($packQty);

        return DB::transaction(function () use ($line, $newPack, $packQty) {
            $target = CartLine::query()
                ->where('cart_id', $line->cart_id)
                ->where('sku_id', $line->sku_id)
                ->where('pack_id', $newPack->id)
                ->where('id', '!=', $line->id)
                ->lockForUpdate()
                ->first();

            if ($target !== null) {
                $merged = $this->setQuantity($target, $target->pack_qty + $packQty);
                $line->delete();

                return $merged;
            }

            $line->update([
                'pack_id' => $newPack->id,
                'pack_base_units' => $newPack->base_units,
                'pack_qty' => $packQty,
                'base_qty' => $packQty * $newPack->base_units,
            ]);

            return $line;
        });
    }

    public function removeLine(CartLine $line): void
    {
        $line->delete();
    }

    private function setQuantity(CartLine $line, int $packQty): CartLine
    {
        $line->update([
            'pack_qty' => $packQty,
            'base_qty' => $packQty * $line->pack_base_units,
        ]);

        return $line;
    }

    private function assertPositiveQty(int $packQty): void
    {
        if ($packQty <= 0) {
            throw new InvalidArgumentException("pack_qty must be a positive integer, got {$packQty}.");
        }
    }
}
