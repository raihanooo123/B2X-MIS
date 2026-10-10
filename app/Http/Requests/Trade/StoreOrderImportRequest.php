<?php

namespace App\Http\Requests\Trade;

use App\Domain\Ordering\BulkEntry\BulkEntryImports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/order-imports (05.1 §14.2): pasted text, or a CSV of at
 * most 5 MB. An oversized file is refused here, before anything is staged.
 * XLSX is not accepted.
 */
class StoreOrderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(['paste', 'csv'])],
            'text' => ['required_if:source,paste', 'nullable', 'string', 'max:500000'],
            'file' => ['required_if:source,csv', 'nullable', 'file', 'max:'.BulkEntryImports::MAX_FILE_KB, 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'text.required_if' => 'Paste at least one line: a product code, then a quantity.',
            'file.required_if' => 'Choose a CSV file to upload.',
            'file.max' => 'The file must be 5 MB or smaller.',
            'file.mimes' => 'Upload a CSV file. Save a spreadsheet as CSV (UTF-8) first.',
            'file.mimetypes' => 'Upload a CSV file. Save a spreadsheet as CSV (UTF-8) first.',
        ];
    }

    public function csvFile(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }
}
