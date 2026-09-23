<?php

namespace App\Domain\Ordering;

/**
 * One reason checkout would be refused, in 06 §4's `details[]` shape so
 * the client renders it exactly like a 422 from checkout itself (06
 * §9.2: "`blockers` is an array of the same `details` shape as §4").
 *
 * `meta` carries the numbers a client needs for a useful message — the
 * minimum, the shortfall, the next valid quantity — never a formatted
 * string to parse back.
 */
final readonly class CheckoutBlocker
{
    /**
     * @param  array<string, int|string|bool|list<string>|null>  $meta
     */
    public function __construct(
        public ?string $field,
        public string $code,
        public string $message,
        public array $meta = [],
    ) {}

    /**
     * @return array{field: ?string, code: string, message: string, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
            'meta' => $this->meta,
        ];
    }
}
