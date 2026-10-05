<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;

/** A 05.16 keyset list with no filters of its own: only the cursor. */
class CursorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cursor' => ['sometimes', 'nullable', 'string', 'max:4096']];
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? $cursor : null;
    }
}
