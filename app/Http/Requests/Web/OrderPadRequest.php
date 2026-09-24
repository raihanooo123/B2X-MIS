<?php

namespace App\Http\Requests\Web;

use App\Domain\Catalogue\OrderPadFilters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /order-pad?q=&category=&brand=&in_stock=1&after=
 *
 * A page, not an API call: a malformed filter is normalised away rather
 * than answered with a validation redirect — an over-long search is cut
 * to OrderPadFilters::MAX_SEARCH_LENGTH, a blank one is no search, and a
 * non-string slug is no filter. An unknown slug is simply a filter that
 * matches nothing, which the empty state names (05.1 §10).
 */
class OrderPadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $text = function (string $key, int $max): ?string {
            $value = $this->query($key);
            if (! is_string($value)) {
                return null;
            }
            $value = trim(mb_substr($value, 0, $max));

            return $value === '' ? null : $value;
        };

        $this->merge([
            'q' => $text('q', OrderPadFilters::MAX_SEARCH_LENGTH),
            'category' => $text('category', 200),
            'brand' => $text('brand', 200),
            'in_stock' => filter_var($this->query('in_stock'), FILTER_VALIDATE_BOOLEAN),
            'after' => $text('after', 4096),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'in_stock' => ['boolean'],
            'after' => ['nullable', 'string'],
        ];
    }

    public function filters(): OrderPadFilters
    {
        $value = fn (string $key): ?string => is_string($this->validated($key)) ? $this->validated($key) : null;

        return new OrderPadFilters(
            search: $value('q'),
            categorySlug: $value('category'),
            brandSlug: $value('brand'),
            inStockOnly: (bool) $this->validated('in_stock'),
        );
    }

    public function cursor(): ?string
    {
        $after = $this->validated('after');

        return is_string($after) ? $after : null;
    }
}
