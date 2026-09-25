<?php

namespace App\Domain\Warehouse\Exceptions;

use RuntimeException;

/**
 * A receipt the warehouse must not book as entered (05.5 §4.3–4.4, §12).
 * Thrown before anything is committed, so a rejected entry writes nothing.
 * Rejected at entry, never accepted and corrected later: corrected batch
 * data is untrustworthy for a recall.
 *
 * `errorCode` is 06 §4's stable machine string; `field` names the input
 * at fault; `meta` carries what the screen needs to say something useful
 * (the prior receipt of a duplicate serial, the expiry warnings to
 * confirm). `status` is 422 for a business-rule refusal and 409 for a
 * state conflict (closed receipt, idempotency key reuse), per 06 §4.1.
 */
final class GoodsInRejectedException extends RuntimeException
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
     * 06 §4's `details[]`: the explicit list when there is one, otherwise
     * this single failure against its field.
     *
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
