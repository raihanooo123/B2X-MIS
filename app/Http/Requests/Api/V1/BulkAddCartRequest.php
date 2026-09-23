<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/cart/bulk-add (06 §8, 05.1 §7).
 *
 * This is the *confirm* step of 05.1 §7's paste/CSV flow: the client
 * sends the lines the buyer accepted on the reconciliation screen,
 * already resolved to SKU ids. Parsing raw pasted text is
 * BulkEntryParser's job (ROADMAP §5, not built). All-or-nothing: one
 * rejected line and nothing is added, with every rejection in
 * `details` — "fix the failures, add the rest" is the client resending
 * without them.
 *
 * 500 lines maximum, matching 05.1 §7.2's threshold above which a CSV
 * goes to a queue instead of being held in a request.
 */
class BulkAddCartRequest extends FormRequest
{
    public const MAX_LINES = 500;

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
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.sku_id' => ['required', 'string', 'ulid'],
            'lines.*.pack_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'lines.*.pack_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.base_qty' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<array{sku_id: string, pack_code: ?string, pack_qty: int, base_qty: ?int}>
     */
    public function items(): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->validated('lines');

        return array_map(fn (array $line) => [
            'sku_id' => (string) $line['sku_id'],
            'pack_code' => isset($line['pack_code']) ? (string) $line['pack_code'] : null,
            'pack_qty' => (int) $line['pack_qty'],
            'base_qty' => isset($line['base_qty']) ? (int) $line['base_qty'] : null,
        ], $lines);
    }
}
