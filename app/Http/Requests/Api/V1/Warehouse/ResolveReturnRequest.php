<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use App\Http\Requests\Api\V1\Warehouse\Concerns\ReplacementAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §13.6: settle a return. `resolution_type` is needed only for faulty
 * goods reported more than 30 days after delivery. An override needs its
 * statutory basis and reason; a refund needs a failed/refused remedy and
 * reason. Requires an Idempotency-Key (06 §6): it refunds. A replacement
 * may go to another address (05.4 §14.2 R10).
 */
class ResolveReturnRequest extends FormRequest
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
        return [
            'resolution_type' => ['nullable', 'string', 'in:credit_note,repair,replacement'],
            'override_basis' => ['nullable', 'string', 'in:impossible,disproportionate'],
            'remedy_outcome' => ['nullable', 'string', 'in:failed,refused'],
            'remedy_reason' => ['nullable', 'string', 'max:2000'],
            ...$this->replacementAddressRules(),
        ];
    }

    public function resolutionType(): ?string
    {
        $type = $this->validated('resolution_type');

        return is_string($type) ? $type : null;
    }
}
