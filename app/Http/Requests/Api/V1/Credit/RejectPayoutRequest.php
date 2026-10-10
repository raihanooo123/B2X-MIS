<?php

namespace App\Http\Requests\Api\V1\Credit;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/credit-payouts/{id}/reject: a pending payout released, with the reason. */
class RejectPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
