<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Cart;
use App\Models\CartLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * GET /api/v1/cart and every line mutation's response.
 *
 * No price, tax or cost of any kind — by design, not omission. Cart
 * lines are not priced (02 §14.3: "nothing here is a resolved-price
 * snapshot"); the pad prices them live via /pricing/bulk-resolve, and
 * checkout/preview prices the whole cart. So this serialiser has no
 * cost field to leak (06 §10).
 *
 * ULIDs only (06 §2): the cart and line `id`s are `public_id`, `sku.id`
 * is the SKU's `public_id`, and packs are identified by `code` because
 * `packs` has no `public_id`.
 *
 * `$resource` may be null — a caller with no cart yet gets an empty
 * cart with `id: null` rather than a 404, and no row is created by
 * reading.
 *
 * @property Cart|null $resource
 */
class CartResource extends JsonResource
{
    public function __construct(?Cart $cart)
    {
        parent::__construct($cart);
    }

    public static function forCart(?Cart $cart): self
    {
        $cart?->load(['lines' => fn ($q) => $q->orderBy('id'), 'lines.sku.product', 'lines.pack']);

        return new self($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cart = $this->resource;
        $lines = $cart === null ? [] : $cart->lines->map(fn (CartLine $line) => $this->line($line))->values()->all();

        return [
            'id' => $cart?->public_id,
            'line_count' => count($lines),
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CartLine $line): array
    {
        $sku = $line->sku ?? throw new RuntimeException("CartLine {$line->id} has no sku loaded.");
        $pack = $line->pack ?? throw new RuntimeException("CartLine {$line->id} has no pack loaded.");

        return [
            'id' => $line->public_id,
            'sku' => [
                'id' => $sku->public_id,
                'sku_code' => $sku->sku_code,
                'name' => $sku->product?->name,
                'variant_label' => $sku->variant_label,
                'status' => $sku->status,
            ],
            'pack' => [
                'code' => $pack->code,
                'label' => $pack->label,
                'base_units' => $line->pack_base_units,
            ],
            'pack_qty' => $line->pack_qty,
            'base_qty' => $line->base_qty,
            'updated_at' => $line->updated_at?->toIso8601ZuluString(),
        ];
    }
}
