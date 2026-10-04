<?php

namespace App\Http\Requests\Web;

use App\Domain\Returns\ProofOfSending;
use Illuminate\Foundation\Http\FormRequest;

/** 05.4 §13.5 "I've sent it back": a photo or PDF of the proof of postage or tracking. */
class ProofOfSendingRequest extends FormRequest
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
            'proof' => ['required', 'file', 'mimes:'.implode(',', ProofOfSending::MIMES), 'max:'.ProofOfSending::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.mimes' => 'Upload a photo (JPG, PNG or WebP) or a PDF.',
            'proof.max' => 'The file must be 10 MB or smaller.',
        ];
    }
}
