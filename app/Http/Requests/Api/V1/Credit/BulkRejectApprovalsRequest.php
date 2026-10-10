<?php

namespace App\Http\Requests\Api\V1\Credit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/approvals/reject (05.2 §18.3, 05.16 §3): reject the
 * loaded rows the approver selected, one reason for all, each request
 * validated on its own. No bulk approval at launch.
 */
class BulkRejectApprovalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'ulid', 'distinct'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('ids')));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
