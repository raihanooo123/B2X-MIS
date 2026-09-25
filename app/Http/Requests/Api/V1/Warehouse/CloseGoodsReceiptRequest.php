<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use App\Domain\Warehouse\VarianceReason;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/warehouse/receipts/{id}/close — 05.5 §4.2 step 7, with the
 * variance decisions of §4.4 / 02 §23.3. Each entry names a PO line by PO
 * number and line number, and gives either a reason or
 * `remainder_expected` (an under-receipt with more to come). Whether each
 * short or over line has one is GoodsInService's check.
 */
class CloseGoodsReceiptRequest extends FormRequest
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
            'variances' => ['sometimes', 'array', 'max:1000'],
            'variances.*' => ['array:po_number,line_no,reason,remainder_expected'],
            'variances.*.po_number' => ['required', 'string', 'max:64'],
            'variances.*.line_no' => ['required', 'integer', 'min:1', 'max:32767'],
            'variances.*.reason' => ['sometimes', 'nullable', Rule::enum(VarianceReason::class)],
            'variances.*.remainder_expected' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Exactly one of the two per entry: a reason, or `remainder_expected:
     * true`. Neither would read as "remainder expected" by default, and
     * both contradict each other.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variances = $this->input('variances', []);
                if (! is_array($variances)) {
                    return;
                }

                foreach ($variances as $i => $variance) {
                    if (! is_array($variance)) {
                        continue;
                    }

                    $hasReason = ($variance['reason'] ?? null) !== null;
                    $remainderExpected = filter_var($variance['remainder_expected'] ?? false, FILTER_VALIDATE_BOOLEAN);

                    if ($hasReason === $remainderExpected) {
                        $validator->errors()->add("variances.{$i}", 'Give either a reason or remainder_expected, not both or neither.');
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{po_number: string, line_no: int, reason: ?VarianceReason}>
     */
    public function variances(): array
    {
        $variances = $this->validated('variances', []);
        if (! is_array($variances)) {
            return [];
        }

        return array_values(array_map(fn (array $v) => [
            'po_number' => trim((string) $v['po_number']),
            'line_no' => (int) $v['line_no'],
            'reason' => isset($v['reason']) ? VarianceReason::from((string) $v['reason']) : null,
        ], $variances));
    }
}
