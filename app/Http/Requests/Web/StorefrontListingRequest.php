<?php

namespace App\Http\Requests\Web;

use App\Domain\Catalogue\OrderPadFilters;
use App\Domain\Storefront\StorefrontCatalogue;
use App\Domain\Storefront\StorefrontFilters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /c/{slug} and /search — `q`, `brand`, `in_stock`, `sort`, `after`.
 * A page, not an API call: a malformed value is normalised away, never a
 * validation redirect (as OrderPadRequest). An unknown sort is name A–Z.
 */
class StorefrontListingRequest extends FormRequest
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
        $sort = $text('sort', 20);

        $this->merge([
            'q' => $text('q', OrderPadFilters::MAX_SEARCH_LENGTH),
            'brand' => $text('brand', 200),
            'in_stock' => filter_var($this->query('in_stock'), FILTER_VALIDATE_BOOLEAN),
            'sort' => in_array($sort, StorefrontCatalogue::SORTS, true) ? $sort : 'name',
            'after' => $text('after', 4096),
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'in_stock' => ['boolean'],
            'sort' => ['string'],
            'after' => ['nullable', 'string'],
        ];
    }

    public function filters(?int $categoryId = null): StorefrontFilters
    {
        $value = fn (string $key): ?string => is_string($this->validated($key)) ? $this->validated($key) : null;

        return new StorefrontFilters(
            search: $value('q'),
            categoryId: $categoryId,
            brandSlug: $value('brand'),
            inStockOnly: (bool) $this->validated('in_stock'),
            sort: (string) $this->validated('sort'),
        );
    }

    public function cursor(): ?string
    {
        $after = $this->validated('after');

        return is_string($after) ? $after : null;
    }
}
