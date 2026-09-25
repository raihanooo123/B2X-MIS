<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use App\Domain\Warehouse\ShortPickReason;
use Illuminate\Validation\Rule;

/**
 * POST …/shipments/{id}/short-picks — 05.5 §5.4. The picked quantity is
 * entered as the picker counts it: whole packs of the ordered pack plus
 * loose units (05.5 AC2 — never units alone). Converted to base units in
 * the controller, where the pack is known.
 */
class ShortPickRequest extends PickLineRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'picked_pack_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'picked_loose_units' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', Rule::enum(ShortPickReason::class)],
        ]);
    }

    public function pickedPackQty(): int
    {
        return (int) $this->validated('picked_pack_qty');
    }

    public function pickedLooseUnits(): int
    {
        return (int) $this->validated('picked_loose_units', 0);
    }

    public function reason(): ShortPickReason
    {
        return ShortPickReason::from((string) $this->validated('reason'));
    }
}
