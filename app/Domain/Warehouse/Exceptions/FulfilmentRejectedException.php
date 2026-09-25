<?php

namespace App\Domain\Warehouse\Exceptions;

use RuntimeException;

/**
 * A picking or dispatch action the warehouse must not take as asked
 * (05.5 §5, §7, §12). Thrown before commit, so a refused action writes
 * nothing. Same shape as GoodsInRejectedException: 06 §4's stable
 * `errorCode`, the field at fault, `meta` for the screen, 422 for a
 * business refusal and 409 for a state conflict.
 *
 * "Blocked, not warned" (05.5 §5.2) is this exception: a serial not
 * allocated to the order cannot be scanned into it at all.
 */
final class FulfilmentRejectedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array{field: ?string, code: string, message: string, meta?: array<string, mixed>}>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $field = null,
        public readonly array $meta = [],
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{field: ?string, code: string, message: string, meta?: array<string, mixed>}>
     */
    public function details(): array
    {
        if ($this->details !== []) {
            return $this->details;
        }

        $detail = ['field' => $this->field, 'code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->meta !== []) {
            $detail['meta'] = $this->meta;
        }

        return [$detail];
    }
}
