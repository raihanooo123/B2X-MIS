<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ordering\CartItemResolver;
use App\Domain\Ordering\CartService;
use App\Domain\Ordering\Exceptions\CartItemRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\ApiException;
use App\Http\Requests\Api\V1\AddCartLineRequest;
use App\Http\Requests\Api\V1\BulkAddCartRequest;
use App\Http\Requests\Api\V1\UpdateCartLineRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Support\CartContext;
use App\Models\Cart;
use App\Models\CartLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Doc 06 §8 — `/cart`, `/cart/lines`, `/cart/lines/{id}`, `/cart/bulk-add`.
 * (`/cart/apply-account-credit` waits on 05.4 §7.5A's balance ledger.)
 *
 * Thin by design: which cart is decided by CartContext, what may be added
 * by CartItemResolver, how it is written by CartService.
 *
 * Tenancy (06 §10): the caller's own cart is the only cart ever loaded,
 * and a line is found by `public_id` *inside* that cart. Another
 * company's line id is therefore a 404 — never fetched, then hidden —
 * with CartPolicy as a second check on the cart that was loaded.
 *
 * Every mutation answers with the whole cart, because one can change
 * more than the addressed line: an add can merge into an existing line,
 * a pack change can merge two lines into one.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService = new CartService,
        private readonly CartItemResolver $itemResolver = new CartItemResolver,
        private readonly CartContext $context = new CartContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $owner = $this->context->owner($request, createGuestToken: false);
        $cart = $owner === null ? null : $this->cartService->findCartFor($owner);

        if ($cart === null) {
            Gate::authorize('viewAny', Cart::class);
        } else {
            Gate::authorize('view', $cart);
        }

        return CartResource::forCart($cart)->response();
    }

    /**
     * 201 + Location when a new line was created; 200 when the quantity
     * was added onto an existing (sku, pack) line.
     */
    public function storeLine(AddCartLineRequest $request): JsonResponse
    {
        $pack = $this->resolveOrReject(fn () => $this->itemResolver->resolve(
            $request->skuPublicId(),
            $request->packCode(),
            $request->packQty(),
            $request->baseQty(),
        ));

        $cart = $this->ownCart($request);
        Gate::authorize('update', $cart);

        ['line' => $line, 'inserted' => $inserted] = $this->cartService->upsertLine($cart, $pack, $request->packQty());

        $response = CartResource::forCart($cart)->response();

        return $inserted
            ? $response->setStatusCode(201)->header('Location', url("/api/v1/cart/lines/{$line->public_id}"))
            : $response;
    }

    public function updateLine(UpdateCartLineRequest $request, string $id): JsonResponse
    {
        [$cart, $line] = $this->findOwnLine($request, $id);
        Gate::authorize('update', $cart);

        $packQty = $request->packQty() ?? $line->pack_qty;
        $packCode = $request->packCode();

        if ($packCode !== null && $packCode !== $line->pack?->code) {
            $pack = $this->resolveOrReject(fn () => $this->itemResolver->packForSku($line->sku_id, $packCode, $packQty, $request->baseQty()));
            $this->cartService->changePack($line, $pack, $packQty);
        } else {
            $expectedBaseQty = $packQty * $line->pack_base_units;
            if ($request->baseQty() !== null && $request->baseQty() !== $expectedBaseQty) {
                throw ApiException::fromCartItemRejections([new CartItemRejectedException('base_qty', 'base_qty_mismatch', "base_qty must equal pack_qty × {$line->pack_base_units}.", [
                    'pack_qty' => $packQty,
                    'base_units' => $line->pack_base_units,
                    'base_qty' => $request->baseQty(),
                    'expected_base_qty' => $expectedBaseQty,
                ])]);
            }

            $this->cartService->updateQuantity($line, $packQty);
        }

        return CartResource::forCart($cart)->response();
    }

    public function destroyLine(Request $request, string $id): Response
    {
        [$cart, $line] = $this->findOwnLine($request, $id);
        Gate::authorize('update', $cart);

        $this->cartService->removeLine($line);

        return response()->noContent();
    }

    /**
     * All-or-nothing (see BulkAddCartRequest). Lines are written in
     * (sku_id, pack_id) order so two concurrent bulk-adds to one cart take
     * their `cart_lines` row locks in the same order and cannot deadlock.
     */
    public function bulkAdd(BulkAddCartRequest $request): JsonResponse
    {
        $items = $request->items();
        ['packs' => $packs, 'rejections' => $rejections] = $this->itemResolver->resolveMany($items);

        if ($rejections !== []) {
            throw ApiException::fromCartItemRejections($rejections, count($items));
        }

        $cart = $this->ownCart($request);
        Gate::authorize('update', $cart);

        $writes = [];
        foreach ($packs as $i => $pack) {
            $writes[] = ['pack' => $pack, 'pack_qty' => $items[$i]['pack_qty']];
        }
        usort($writes, fn (array $a, array $b) => [$a['pack']->sku_id, $a['pack']->id] <=> [$b['pack']->sku_id, $b['pack']->id]);

        DB::transaction(function () use ($cart, $writes) {
            foreach ($writes as $write) {
                $this->cartService->addLine($cart, $write['pack'], $write['pack_qty']);
            }
        });

        return CartResource::forCart($cart)->response();
    }

    private function ownCart(Request $request): Cart
    {
        $owner = $this->context->owner($request, createGuestToken: true)
            ?? throw new LogicException('createGuestToken: true always yields an owner.');

        return $this->cartService->cartFor($owner);
    }

    /**
     * @return array{0: Cart, 1: CartLine}
     */
    private function findOwnLine(Request $request, string $publicId): array
    {
        $owner = $this->context->owner($request, createGuestToken: false);
        $cart = $owner === null ? null : $this->cartService->findCartFor($owner);

        $line = $cart?->lines()->with('pack')->where('public_id', $publicId)->first();

        if ($cart === null || $line === null) {
            throw new ApiException(404, 'not_found', 'Cart line not found.');
        }

        return [$cart, $line];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $resolve
     * @return T
     */
    private function resolveOrReject(callable $resolve): mixed
    {
        try {
            return $resolve();
        } catch (CartItemRejectedException $e) {
            throw ApiException::fromCartItemRejections([$e]);
        }
    }
}
