<?php

namespace App\Http\Requests\Web;

use App\Http\Requests\Concerns\AuthFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.15 §6.3 "Save your details — set a password", from a guest's order
 * page. The email is the order's own, so it is not asked for; the rest is
 * what public registration asks (05.13 §5.2).
 */
class GuestAccountRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'password' => AuthFields::newPassword(),
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, password: string}
     */
    public function person(string $email): array
    {
        return [
            'first_name' => trim((string) $this->validated('first_name')),
            'last_name' => trim((string) $this->validated('last_name')),
            'email' => $email,
            'password' => (string) $this->validated('password'),
        ];
    }
}
