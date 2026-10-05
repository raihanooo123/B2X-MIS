<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use App\Http\Requests\Api\V1\Warehouse\Concerns\ReplacementAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §14.2 R7 (Q-R2): send a replacement before the faulty goods come
 * back — staff only, always with a reason (kept in the audit log), and
 * optionally to another address (R10). Requires an Idempotency-Key (06 §6).
 */
class AdvanceReplacementRequest extends FormRequest
{
    use ReplacementAddress;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500'], ...$this->replacementAddressRules()];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
