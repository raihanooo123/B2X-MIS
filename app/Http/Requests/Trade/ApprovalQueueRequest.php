<?php

namespace App\Http\Requests\Trade;

use App\Domain\Credit\ApprovalQueue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /trade/approvals (05.2 §18.3): URL-backed filters (05.16 §3) and
 * the keyset cursor. Dates are UK calendar days.
 */
class ApprovalQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'decided'])],
            'buyer' => ['sometimes', 'nullable', 'string', 'ulid'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(ApprovalQueue::SORTS))],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }

    /** @return array{status: string, buyer: ?string, from: ?string, to: ?string, q: ?string, sort: string} */
    public function filters(): array
    {
        $text = fn (string $key): ?string => is_string($this->validated($key)) && trim((string) $this->validated($key)) !== '' ? trim((string) $this->validated($key)) : null;

        return [
            'status' => $this->validated('status') === 'decided' ? 'decided' : 'pending',
            'buyer' => $text('buyer'),
            'from' => $text('from'),
            'to' => $text('to'),
            'q' => $text('q'),
            'sort' => is_string($this->validated('sort')) ? (string) $this->validated('sort') : 'requested_desc',
        ];
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? $cursor : null;
    }
}
