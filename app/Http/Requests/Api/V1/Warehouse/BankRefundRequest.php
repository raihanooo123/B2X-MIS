<?php

namespace App\Http\Requests\Api\V1\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/** 05.4 §13.6: the bank transfer's reference, when accounts pay a refund by transfer. */
class BankRefundRequest extends FormRequest
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
        return ['reference' => ['required', 'string', 'min:3', 'max:100']];
    }

    public function reference(): string
    {
        return trim((string) $this->validated('reference'));
    }
}
