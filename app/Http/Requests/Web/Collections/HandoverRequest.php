<?php

namespace App\Http\Requests\Web\Collections;

use Illuminate\Foundation\Http\FormRequest;

/** 05.6 §7A.6 steps 1 and 4: who collected, then the handover. */
class HandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'collector_name' => ['nullable', 'string', 'max:191'],
            'identity_checked' => ['accepted'],
        ];
    }

    public function collectorName(): ?string
    {
        $name = trim((string) $this->validated('collector_name'));

        return $name === '' ? null : $name;
    }
}
