<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/warehouse/stocktakes — start (or resume) the stocktake at a
 * location (05.5 §8). `blind` hides system figures while counting.
 */
class StartStocktakeRequest extends FormRequest
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
            'location_code' => ['required', 'string', 'max:64'],
            'blind' => ['sometimes', 'boolean'],
        ];
    }

    public function locationCode(): string
    {
        return trim((string) $this->validated('location_code'));
    }

    public function blind(): bool
    {
        return (bool) $this->validated('blind', false);
    }
}
