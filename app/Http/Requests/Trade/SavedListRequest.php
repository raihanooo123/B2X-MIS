<?php

namespace App\Http\Requests\Trade;

use App\Domain\Ordering\BulkEntry\SavedLists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST and PATCH /api/v1/saved-lists (05.1 §14.2). A PATCH names the
 * version it edits; lines replace the list's lines (public SKU ids).
 */
class SavedListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $patch = $this->isMethod('PATCH');

        return [
            'name' => [$patch ? 'sometimes' : 'required', 'string', 'min:1', 'max:80'],
            'from_cart' => ['sometimes', 'boolean'],
            'version' => [$patch ? 'required' : 'prohibited', 'integer', 'min:0'],
            'lines' => ['sometimes', 'array', 'max:'.SavedLists::MAX_LINES],
            'lines.*.sku_id' => ['required', 'string', 'ulid'],
            'lines.*.pack_code' => ['required', 'string', 'max:64'],
            'lines.*.pack_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return list<array{sku_id: string, pack_code: string, pack_qty: int}>|null */
    public function lines(): ?array
    {
        $lines = $this->validated('lines');
        if (! is_array($lines)) {
            return null;
        }

        return array_values(array_map(fn (array $l): array => ['sku_id' => (string) $l['sku_id'], 'pack_code' => (string) $l['pack_code'], 'pack_qty' => (int) $l['pack_qty']], $lines));
    }
}
