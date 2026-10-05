<?php

namespace App\Domain\Storefront;

/**
 * The storefront grid's state (05.15 §5.2): search text, the category
 * (resolved from its slug by the page), brand, "in stock only" and the
 * sort. Everything lives in the query string, so a filtered page is a
 * link that survives Back.
 */
final readonly class StorefrontFilters
{
    public function __construct(
        public ?string $search = null,
        public ?int $categoryId = null,
        public ?string $brandSlug = null,
        public bool $inStockOnly = false,
        public string $sort = 'name',
    ) {}

    public function withSort(string $sort): self
    {
        return new self($this->search, $this->categoryId, $this->brandSlug, $this->inStockOnly, $sort);
    }

    public function withoutBrand(): self
    {
        return new self($this->search, $this->categoryId, null, $this->inStockOnly, $this->sort);
    }

    /** Ties a page cursor to the filters it was made under. */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([$this->search, $this->categoryId, $this->brandSlug, $this->inStockOnly, $this->sort]));
    }

    /**
     * The query-string shape the page echoes back.
     *
     * @return array{q: string|null, brand: string|null, in_stock: bool, sort: string}
     */
    public function toArray(): array
    {
        return ['q' => $this->search, 'brand' => $this->brandSlug, 'in_stock' => $this->inStockOnly, 'sort' => $this->sort];
    }
}
