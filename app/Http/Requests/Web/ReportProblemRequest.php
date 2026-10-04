<?php

namespace App\Http\Requests\Web;

use App\Domain\Returns\FaultReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * 05.15 §7.3 "Report a problem" (05.4 §13.4): which items, how many packs,
 * what is wrong, a description, and up to 5 photographs.
 */
class ReportProblemRequest extends FormRequest
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
            'reason' => ['required', 'string', 'in:'.implode(',', FaultReports::REASONS)],
            'detail' => ['required', 'string', 'min:5', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.line_no' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.pack_qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'photos' => ['sometimes', 'array', 'max:5'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function packQtyByLineNo(): array
    {
        $out = [];
        /** @var list<array{line_no: int|string, pack_qty: int|string}> $lines */
        $lines = $this->validated('lines');
        foreach ($lines as $line) {
            $out[(int) $line['line_no']] = (int) $line['pack_qty'];
        }

        return $out;
    }

    /**
     * @return list<UploadedFile>
     */
    public function photos(): array
    {
        $files = $this->file('photos');

        return is_array($files) ? array_values($files) : [];
    }
}
