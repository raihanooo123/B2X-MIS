<?php

namespace App\Domain\Ordering;

use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Pack;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * itself re-validates purchasability via PriceResolver regardless;
 * checkout preview (CheckoutPreviewService) reports them as blockers.
 *
 * Concurrency. Two races existed in the original find-then-insert shape,
 * both fixed here without any lock outside this aggregate:
 *
 *   1. Two simultaneous first-adds of one (cart, sku, pack): the
 *      `SELECT ... FOR UPDATE` locks nothing when no row exists yet, so
 *      both requests saw "no line" and both INSERTed — one then died on
 *      `cart_lines_cart_sku_pack_uq` as a 500, losing that add. addLine()
 *      is now a single `INSERT ... ON CONFLICT DO UPDATE`, which Postgres
 *      resolves atomically: exactly one row, both quantities summed.
 *   2. Two simultaneous first requests for an owner with no cart yet:
 *      `carts` has no unique key on `company_id`/`user_id` (02 §14.3 —
 *      only `session_token`), so both created a cart and the buyer saw
 *      their lines split across two. cartFor() now serialises creation
 *      per owner with a transaction-scoped advisory lock. Guest carts
 *      need no lock: `carts_session_token_uq` already arbitrates.
 *
 * Neither path touches `companies`, `collection_slots` or `stock_levels`,
 * so none of this interacts with the allocation lock order (CLAUDE.md
 * invariant 6).
 */
final class CartService
{
    /**
     * The owner's cart, if it has one. Read-only — never creates.
     */
    public function findCartFor(CartOwner $owner): ?Cart
    {
        return $this->ownerQuery($owner)->first();
    }

    /**
     * The owner's cart, creating it on first use. See the class docblock
     * (race 2) for why creation is serialised per owner.
     */
    public function cartFor(CartOwner $owner): Cart
    {
        if ($owner->isGuest()) {
            DB::statement(
                'INSERT INTO carts (public_id, session_token) VALUES (?, ?) ON CONFLICT ON CONSTRAINT carts_session_token_uq DO NOTHING',
                [(string) Str::ulid(), $owner->sessionToken],
            );

            // Guest lookups also require company_id/user_id IS NULL, so a
            // token belonging to an adopted cart finds nothing here — and
            // the INSERT above cannot create a second row for it either.
            return $this->ownerQuery($owner)->first()
                ?? throw new InvalidArgumentException('This session token belongs to a cart that is no longer a guest cart.');
        }

        return DB::transaction(function () use ($owner) {
            $this->lockOwner($owner);

            return $this->ownerQuery($owner)->first() ?? Cart::create([
                'session_token' => (string) Str::ulid(),
                'company_id' => $owner->companyId,
                'user_id' => $owner->userId,
            ]);
        });
    }

    /**
     * Guest cart merge at login (02 §14.3: `session_token` "is how a
     * guest cart is merged onto a company/user at login").
     *
     * - Owner has no cart yet: the guest cart is adopted in place —
     *   company_id/user_id filled in, line ids unchanged.
     * - Owner already has one: every guest line is added onto it with
     *   addLine()'s summing semantics (same sku+pack → quantities add,
     *   matching 05.1 §7.1's "duplicate code — merged"), then the guest
     *   cart is deleted (its lines cascade).
     *
     * Runs under the same per-owner lock as cartFor(), so a merge racing
     * another first-add for the same company cannot create a second cart.
     */
    public function mergeGuestCart(string $sessionToken, CartOwner $owner): ?Cart
    {
        if ($owner->isGuest()) {
            throw new InvalidArgumentException('Cannot merge a guest cart onto another guest.');
        }

        return DB::transaction(function () use ($sessionToken, $owner) {
            $guest = $this->ownerQuery(CartOwner::guest($sessionToken))->lockForUpdate()->first();

            $this->lockOwner($owner);
            $target = $this->ownerQuery($owner)->first();

            if ($guest === null) {
                return $target;
            }

            if ($target === null) {
                $guest->update(['company_id' => $owner->companyId, 'user_id' => $owner->userId]);

                return $guest;
            }

            $guestLines = $guest->lines()->with('pack')->orderBy('sku_id')->orderBy('pack_id')->get();
            foreach ($guestLines as $line) {
                $pack = $line->pack ?? throw new InvalidArgumentException("CartLine {$line->id} has no pack.");
                $this->addLine($target, $pack, $line->pack_qty);
            }

            $guest->delete();

            return $target;
        });
    }

    /**
     * Adds `packQty` of `$pack` to the cart. If a line for this exact
     * (cart, sku, pack) already exists — `cart_lines_cart_sku_pack_uq` —
     * the quantity is added onto it, matching how a buyer expects "add 5
     * more" to behave.
     *
     * One statement, not find-then-insert: see the class docblock (race
     * 1). `pack_base_units` is refreshed from the pack on conflict, as
     * 02 §14.3 says the application does on every write.
     */
    public function addLine(Cart $cart, Pack $pack, int $packQty): CartLine
    {
        return $this->upsertLine($cart, $pack, $packQty)['line'];
    }

    /**
     * addLine(), also reporting whether a new row was inserted (true) or
     * an existing line absorbed the quantity (false) — the API returns
     * 201 for the first, 200 for the second.
     *
     * @return array{line: CartLine, inserted: bool}
     */
    public function upsertLine(Cart $cart, Pack $pack, int $packQty): array
    {
        $this->assertPositiveQty($packQty);

        // `xmax = 0` is true only for a freshly inserted row version —
        // the standard Postgres idiom for telling the two ON CONFLICT
        // outcomes apart in RETURNING.
        $row = DB::selectOne(<<<'SQL'
            INSERT INTO cart_lines (public_id, cart_id, sku_id, pack_id, pack_qty, pack_base_units, base_qty)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT ON CONSTRAINT cart_lines_cart_sku_pack_uq DO UPDATE SET
              pack_qty        = cart_lines.pack_qty + EXCLUDED.pack_qty,
              pack_base_units = EXCLUDED.pack_base_units,
              base_qty        = (cart_lines.pack_qty + EXCLUDED.pack_qty) * EXCLUDED.pack_base_units,
              updated_at      = now()
            RETURNING *, (xmax = 0) AS inserted
            SQL, [
            (string) Str::ulid(),
            $cart->id,
            $pack->sku_id,
            $pack->id,
            $packQty,
            $pack->base_units,
            $packQty * $pack->base_units,
        ]);

        $attributes = (array) $row;
        $inserted = (bool) $attributes['inserted'];
        unset($attributes['inserted']);

        return ['line' => (new CartLine)->newFromBuilder($attributes), 'inserted' => $inserted];
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

        // The target-line lookup below has the same find-then-write gap
        // addLine() used to: a concurrent first-add of (sku, $newPack)
        // can land between it and the UPDATE. On that collision, retry
        // once — the target now exists, so the retry takes the merge
        // branch. Only possible at the outermost level: inside a caller's
        // transaction the failed statement has already aborted it.
        if (DB::transactionLevel() === 0) {
            try {
                return $this->changePackOnce($line, $newPack, $packQty);
            } catch (UniqueConstraintViolationException) {
                return $this->changePackOnce($line->refresh(), $newPack, $packQty);
            }
        }

        return $this->changePackOnce($line, $newPack, $packQty);
    }

    private function changePackOnce(CartLine $line, Pack $newPack, int $packQty): CartLine
    {
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

    /**
     * @return Builder<Cart>
     */
    private function ownerQuery(CartOwner $owner): Builder
    {
        $query = Cart::query();

        if ($owner->isGuest()) {
            return $query->where('session_token', $owner->sessionToken)
                ->whereNull('company_id')
                ->whereNull('user_id');
        }

        if ($owner->companyId !== null) {
            // carts_company_updated_idx. Newest first, so a company that
            // accumulated two carts before cartFor() serialised creation
            // keeps using the most recently touched one.
            return $query->where('company_id', $owner->companyId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id');
        }

        // carts_user_idx
        return $query->where('user_id', $owner->userId)
            ->whereNull('company_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /**
     * Transaction-scoped advisory lock on the owner identity. Advisory,
     * not a `companies` row lock, so a first add-to-cart never queues
     * behind a checkout holding that company's credit lock.
     */
    private function lockOwner(CartOwner $owner): void
    {
        $key = $owner->companyId !== null ? "carts:company:{$owner->companyId}" : "carts:user:{$owner->userId}";

        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
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
