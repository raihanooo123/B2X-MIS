<?php

namespace App\Domain\Catalogue;

/**
 * The order pad's narrowing controls (05.1 §3 U2, §4.1): free-text
 * search over SKU code and product name, a category (its whole subtree),
 * a brand, and "in stock only". Categories and brands are addressed by
 * slug — readable in a shared URL, and never an internal id (06 §2).
 *
 * `fingerprint()` is folded into the keyset cursor, so a cursor taken
 * under one set of filters can never page through another: changing a
 * filter restarts at row 1 rather than skipping or repeating rows.
 */
final readonly class OrderPadFilters
{
    public const MAX_SEARCH_LENGTH = 100;

    public function __construct(
        public ?string $search = null,
        public ?string $categorySlug = null,
        public ?string $brandSlug = null,
        public bool $inStockOnly = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->search === null && $this->categorySlug === null && $this->brandSlug === null && ! $this->inStockOnly;
    }

    public function fingerprint(): string
    {
        return hash('xxh128', (string) json_encode($this->toArray()));
    }

    /**
     * The shape the page receives back as its `filters` prop, and the
     * query-string keys it sends.
     *
     * @return array{q: string|null, category: string|null, brand: string|null, in_stock: bool}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->search,
            'category' => $this->categorySlug,
            'brand' => $this->brandSlug,
            'in_stock' => $this->inStockOnly,
        ];
    }
}
