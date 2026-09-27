<?php

namespace App\Domain\Identity;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Whole pence only, shared by HTTP, Filament and the domain services. */
final readonly class CompanyMemberSettings
{
    public function __construct(
        public CompanyMemberRole $role,
        public ?int $orderLimitMinor,
        public bool $requiresApproval,
    ) {
        if ($orderLimitMinor !== null && $orderLimitMinor < 0) {
            throw new \InvalidArgumentException('Order limits cannot be negative.');
        }
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(CompanyMemberRole::class)],
            'order_limit_minor' => ['nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'requires_approval' => ['required', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $valid = Validator::make($data, self::rules())->validate();

        return new self(
            CompanyMemberRole::from($valid['role']),
            isset($valid['order_limit_minor']) && $valid['order_limit_minor'] !== '' ? (int) $valid['order_limit_minor'] : null,
            (bool) $valid['requires_approval'],
        );
    }

    /** @return array{role: string, order_limit_minor: ?int, requires_approval: bool} */
    public function attributes(): array
    {
        return ['role' => $this->role->value, 'order_limit_minor' => $this->orderLimitMinor, 'requires_approval' => $this->requiresApproval];
    }
}
