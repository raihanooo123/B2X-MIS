<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

/** 05.13 §5.2 — public customer registration. */
class RegisterPublicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => AuthFields::email(),
            'password' => AuthFields::newPassword(),
            'terms' => ['accepted'],
        ];
    }

    /** @return array{first_name: string, last_name: string, email: string, password: string} */
    public function person(): array
    {
        return [
            'first_name' => trim((string) $this->validated('first_name')),
            'last_name' => trim((string) $this->validated('last_name')),
            'email' => trim((string) $this->validated('email')),
            'password' => (string) $this->validated('password'),
        ];
    }
}
