<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §13.6: settle a return. `resolution_type` is needed only for faulty
 * goods reported more than 30 days after delivery (repair, replacement or a
 * refund). Requires an Idempotency-Key (06 §6): it refunds.
 */
class ResolveReturnRequest extends FormRequest
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
        return ['resolution_type' => ['nullable', 'string', 'in:credit_note,repair,replacement']];
    }

    public function resolutionType(): ?string
    {
        $type = $this->validated('resolution_type');

        return is_string($type) ? $type : null;
    }
}
