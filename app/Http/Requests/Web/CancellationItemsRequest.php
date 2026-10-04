<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §13.3 "Cancel items": which lines of a dispatched order, by the
 * order's line number, and how many packs of each. Zeros are allowed and
 * ignored; who may ask is decided by the route (policy, or guest link).
 */
class CancellationItemsRequest extends FormRequest
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
            'lines.*.pack_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<int, int> line_no => packs
     */
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
}
