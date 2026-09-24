<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => AuthFields::email()];
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }
}
