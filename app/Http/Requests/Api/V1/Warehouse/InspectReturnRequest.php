<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §7.4, §13.5: per RMA line, how many base units are restocked,
 * quarantined and written off (the rest go back to the customer), and any
 * deduction for diminished value, in pence, with its reason. Requires an
 * Idempotency-Key (06 §6): restocking moves stock.
 */
class InspectReturnRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_no' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.restock' => ['required', 'integer', 'min:0'],
            'lines.*.quarantine' => ['required', 'integer', 'min:0'],
            'lines.*.write_off' => ['required', 'integer', 'min:0'],
            'lines.*.diminished_value_minor' => ['sometimes', 'integer', 'min:0'],
            'lines.*.diminished_value_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.disposition_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, array{restock: int, quarantine: int, write_off: int, diminished_value_minor: int, diminished_value_reason: string|null, disposition_reason: string|null}>
     */
    public function decisions(): array
    {
        $out = [];
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->validated('lines');
        foreach ($lines as $line) {
            $out[(int) $line['line_no']] = [
                'restock' => (int) $line['restock'],
                'quarantine' => (int) $line['quarantine'],
                'write_off' => (int) $line['write_off'],
                'diminished_value_minor' => (int) ($line['diminished_value_minor'] ?? 0),
                'diminished_value_reason' => isset($line['diminished_value_reason']) ? (string) $line['diminished_value_reason'] : null,
                'disposition_reason' => isset($line['disposition_reason']) ? (string) $line['disposition_reason'] : null,
            ];
        }

        return $out;
    }
}
