<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/** 05.4 §13.5: why a consumer's proof of sending is not accepted — sent to them. */
class RejectReturnProofRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
