<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A 05.17 history list (05.16 §3, 06 §5.1): URL-backed search, UK-day date
 * range, a whitelisted date sort, the keyset cursor and an optional page
 * size (50 by default, 100 at most). Subclasses add their own filter.
 */
abstract class TradeListRequest extends FormRequest
{
    /** @return list<string> the sort keys this list allows */
    abstract protected function sorts(): array;

    /** @return array<string, mixed> rules for the list's own filter */
    abstract protected function filterRules(): array;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', 'nullable', Rule::in($this->sorts())],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? $cursor : null;
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page');

        return is_numeric($value) ? (int) $value : 50;
    }

    protected function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array{q: ?string, from: ?string, to: ?string, sort: string} */
    protected function common(): array
    {
        return [
            'q' => $this->text('q'),
            'from' => $this->text('from'),
            'to' => $this->text('to'),
            'sort' => $this->text('sort') ?? $this->sorts()[0],
        ];
    }
}
