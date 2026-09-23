<?php

namespace App\Domain\Ordering\Exceptions;

use RuntimeException;

/**
 * A well-formed add/update the business declines — unknown SKU,
 * inactive SKU, unknown or unsellable pack, pack/base quantity mismatch.
 * `code` is one stable machine string for 06 §4's `details[].code`.
 *
 * `lineIndex` is the item's 0-based position in a bulk request (the same
 * N as in `field` = `lines.N.…`), null for a single-item add/update.
 */
final class CartItemRejectedException extends RuntimeException
{
    /**
     * @param  array<string, int|string|null>  $meta
     */
    public function __construct(
        public readonly string $field,
        public readonly string $errorCode,
        string $message,
        public readonly array $meta = [],
        public readonly ?int $lineIndex = null,
    ) {
        parent::__construct($message);
    }

    public function atLine(int $lineIndex): self
    {
        return new self($this->field, $this->errorCode, $this->getMessage(), $this->meta, $lineIndex);
    }
}
