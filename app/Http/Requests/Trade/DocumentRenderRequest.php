<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/document-renders (05.17 §4): which source to prepare, by
 * type and public ULID. No URL, HTML or path is ever accepted.
 */
class DocumentRenderRequest extends FormRequest
{
    public const TYPES = ['invoice', 'credit_note', 'statement'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'source' => ['required', 'string', 'ulid'],
        ];
    }
}
