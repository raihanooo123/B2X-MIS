<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/** 05.10 §2: whole pack quantities, one idempotent request, and a trade reason. */
class PartialCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_no' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.pack_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason_detail' => ['nullable', 'string', 'max:500'],
            'reason_code' => ['nullable', 'string', 'in:below_break_override'],
            'customer_notified_at' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }

    /** @return array<int, int> line_no => packs */
    public function packQtyByLineNo(): array
    {
        $out = [];
        /** @var list<array{line_no: int|string, pack_qty: int|string}> $lines */
        $lines = $this->validated('lines');
        foreach ($lines as $line) {
            $out[(int) $line['line_no']] = (int) $line['pack_qty'];
        }

        return $out;
    }

    public function clientToken(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
