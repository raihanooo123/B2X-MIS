<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/cart/lines/{id} (06 §8). Partial: `pack_qty`, `pack_code`
 * or both. A `pack_code` different from the line's current pack is a
 * pack change — a line UPDATE, never delete-and-re-add (05.1 §8.3).
 */
class UpdateCartLineRequest extends FormRequest
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
            'pack_qty' => ['required_without:pack_code', 'integer', 'min:1', 'max:1000000'],
            'pack_code' => ['required_without:pack_qty', 'string', 'max:64'],
            'base_qty' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function packQty(): ?int
    {
        $qty = $this->validated('pack_qty');

        return $qty === null ? null : (int) $qty;
    }

    public function packCode(): ?string
    {
        $code = $this->validated('pack_code');

        return $code === null ? null : (string) $code;
    }

    public function baseQty(): ?int
    {
        $qty = $this->validated('base_qty');

        return $qty === null ? null : (int) $qty;
    }
}
