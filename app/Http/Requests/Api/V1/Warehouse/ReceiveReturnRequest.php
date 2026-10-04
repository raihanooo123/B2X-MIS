<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §7.3: what arrived on each line of a returned parcel, in base units.
 * Requires an Idempotency-Key (06 §6), like every warehouse write.
 */
class ReceiveReturnRequest extends FormRequest
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
            'lines.*.received_base_qty' => ['required', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return array<int, int> RMA line_no => base units received
     */
    public function receivedByLineNo(): array
    {
        $out = [];
        /** @var list<array{line_no: int|string, received_base_qty: int|string}> $lines */
        $lines = $this->validated('lines');
        foreach ($lines as $line) {
            $out[(int) $line['line_no']] = (int) $line['received_base_qty'];
        }

        return $out;
    }
}
