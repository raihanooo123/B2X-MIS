<?php

namespace App\Http\Requests\Api\V1\Credit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/approvals/{id}/approve|reject and the credit-exception
 * equivalents (05.2 §18.3). 06 §18: the decision names the status it
 * expects to change, so a stale page cannot decide a request someone
 * else already decided. A rejection needs a reason the buyer is shown.
 */
class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_status' => ['required', 'in:pending'],
            'reason' => [$this->routeIs('*.reject') ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
