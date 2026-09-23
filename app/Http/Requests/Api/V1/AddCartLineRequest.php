<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/cart/lines (06 §8).
 *
 * `sku_id` is the SKU's ULID public_id (06 §2). `pack_code` addresses the
 * pack within that SKU — packs have no public_id (see CartItemResolver);
 * omitted means the default sell pack. `base_qty` is optional and, when
 * sent, must equal `pack_qty × base_units` (06 §3.2) — checked in
 * CartItemResolver, where the pack is known.
 *
 * Authorisation is CartPolicy, applied by the controller once the cart
 * is resolved — there is no cart to authorise against at validation time.
 */
class AddCartLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'string', 'ulid'],
            'pack_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pack_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'base_qty' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function skuPublicId(): string
    {
        return (string) $this->validated('sku_id');
    }

    public function packCode(): ?string
    {
        $code = $this->validated('pack_code');

        return $code === null ? null : (string) $code;
    }

    public function packQty(): int
    {
        return (int) $this->validated('pack_qty');
    }

    public function baseQty(): ?int
    {
        $qty = $this->validated('base_qty');

        return $qty === null ? null : (int) $qty;
    }
}
