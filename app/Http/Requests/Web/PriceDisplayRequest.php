<?php

namespace App\Http\Requests\Web;

use App\Http\Support\PriceDisplay;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 05.15 §4.1: `mode` is `gross` (Inc VAT) or `net` (Ex VAT). Trade users
 * and staff follow their company or role and are refused the switch.
 */
class PriceDisplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PriceDisplay::canSwitch($this);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['mode' => ['required', 'string', 'in:gross,net']];
    }

    /** @return 'gross'|'net' */
    public function mode(): string
    {
        return $this->validated('mode') === 'net' ? 'net' : 'gross';
    }
}
