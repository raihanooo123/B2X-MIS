<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.4 §13.3 (S6e): a cancellation the customer made by email or phone.
 * `notified_at` is when they told us (ISO 8601, or a UK local date-time);
 * the window is judged at that time, not when it is recorded.
 */
class RecordCancellationRequest extends FormRequest
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
            'order_number' => ['required', 'string', 'max:32'],
            'notified_at' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_no' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.pack_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function orderNumber(): string
    {
        return strtoupper(trim((string) $this->validated('order_number')));
    }

    /** A value with no offset is read as UK local time. */
    public function notifiedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('notified_at'), 'Europe/London');
    }

    /**
     * @return array<int, int>
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
