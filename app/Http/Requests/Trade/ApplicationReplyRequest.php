<?php

namespace App\Http\Requests\Trade;

use App\Domain\Accounts\ApplicantApplications;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /trade/application/reply (05.17 §2): a bounded answer and up to
 * three documents — PDF, JPEG or PNG, checked by content, 5 MB each.
 */
class ApplicationReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:'.ApplicantApplications::REPLY_MAX],
            'files' => ['sometimes', 'array', 'max:'.ApplicantApplications::FILES_MAX],
            'files.*' => ['file', 'mimes:'.implode(',', ApplicantApplications::FILE_TYPES), 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.ApplicantApplications::FILE_MAX_KB],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.required' => 'Write your answer to the request.',
            'files.max' => 'Attach at most '.ApplicantApplications::FILES_MAX.' documents.',
            'files.*.mimes' => 'Documents must be PDF, JPEG or PNG files.',
            'files.*.mimetypes' => 'Documents must be PDF, JPEG or PNG files.',
            'files.*.max' => 'Each document must be 5 MB or smaller.',
        ];
    }

    public function reply(): string
    {
        return trim((string) $this->validated('message'));
    }

    /** @return list<UploadedFile> */
    public function evidence(): array
    {
        $files = $this->file('files', []);

        return array_values(is_array($files) ? $files : []);
    }
}
