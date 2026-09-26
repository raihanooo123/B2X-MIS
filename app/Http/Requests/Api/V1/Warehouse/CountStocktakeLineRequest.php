<?php

namespace App\Http\Requests\Api\V1\Warehouse;

/**
 * POST …/stocktakes/{id}/lines — set a count, as the operative counts it:
 * whole packs of `pack_code` plus loose units (05.5 AC2). Converted to
 * base units in the controller, where the pack is known.
 */
class CountStocktakeLineRequest extends StocktakeIdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'pack_code' => ['required', 'string', 'max:64'],
            'pack_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'loose_units' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);
    }

    public function packCode(): string
    {
        return (string) $this->validated('pack_code');
    }

    public function packQty(): int
    {
        return (int) $this->validated('pack_qty');
    }

    public function looseUnits(): int
    {
        return (int) $this->validated('loose_units', 0);
    }
}
