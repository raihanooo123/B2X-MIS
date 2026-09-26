<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use App\Domain\Warehouse\StocktakeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST …/stocktakes/{id}/post — 05.5 §8, 02 §24.3. A reason per variance
 * line, each line named by SKU and batch. Whether every variance line has
 * one is StocktakeService's check, against the figures at posting.
 */
class PostStocktakeRequest extends FormRequest
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
            'reasons' => ['sometimes', 'array', 'max:5000'],
            'reasons.*' => ['array:sku_id,batch_code,reason'],
            'reasons.*.sku_id' => ['required', 'string', 'ulid'],
            'reasons.*.batch_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reasons.*.reason' => ['required', Rule::enum(StocktakeReason::class)],
        ];
    }

    /**
     * @return list<array{sku_id: string, batch_code: ?string, reason: StocktakeReason}>
     */
    public function reasons(): array
    {
        $reasons = $this->validated('reasons', []);
        if (! is_array($reasons)) {
            return [];
        }

        return array_values(array_map(fn (array $r) => [
            'sku_id' => (string) $r['sku_id'],
            'batch_code' => isset($r['batch_code']) && trim((string) $r['batch_code']) !== '' ? trim((string) $r['batch_code']) : null,
            'reason' => StocktakeReason::from((string) $r['reason']),
        ], $reasons));
    }
}
