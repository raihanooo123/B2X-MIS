<?php

namespace App\Domain\Identity;

use App\Models\CompanyUser;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A member's company role, order limit and approval flag (02 §4.4), shared
 * by the storefront, Filament and the domain services. People type the
 * order limit in pounds (`order_limit`, e.g. "2500" or "2500.50"); it is
 * stored as whole pence, converted once here with string and integer
 * arithmetic only (CLAUDE.md invariant 1).
 */
final readonly class CompanyMemberSettings
{
    /** Up to £999,999,999.99 — far above any real order limit. */
    private const POUNDS_PATTERN = '/^\d{1,9}(\.\d{1,2})?$/';

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
            'order_limit' => ['nullable', 'string', 'regex:'.self::POUNDS_PATTERN],
            'requires_approval' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['order_limit.regex' => 'Enter the order limit in pounds, e.g. 2500 or 2500.50.'];
    }

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        if (is_int($data['order_limit'] ?? null)) {
            $data['order_limit'] = (string) $data['order_limit'];
        }
        $valid = Validator::make($data, self::rules(), self::messages())->validate();
        $limit = isset($valid['order_limit']) ? trim((string) $valid['order_limit']) : '';

        return new self(
            CompanyMemberRole::from($valid['role']),
            $limit === '' ? null : self::poundsToMinor($limit),
            (bool) $valid['requires_approval'],
        );
    }

    /**
     * A membership as form values, the order limit back in pounds.
     *
     * @return array{role: string, order_limit: ?string, requires_approval: bool}
     */
    public static function formValues(CompanyUser $membership): array
    {
        return [
            'role' => $membership->role,
            'order_limit' => self::minorToPounds($membership->order_limit_minor),
            'requires_approval' => $membership->requires_approval,
        ];
    }

    /** @return array{role: string, order_limit_minor: ?int, requires_approval: bool} */
    public function attributes(): array
    {
        return ['role' => $this->role->value, 'order_limit_minor' => $this->orderLimitMinor, 'requires_approval' => $this->requiresApproval];
    }

    /** "2500.5" → 250050. The input already matched POUNDS_PATTERN. */
    public static function poundsToMinor(string $pounds): int
    {
        [$whole, $fraction] = array_pad(explode('.', $pounds, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** 250050 → "2500.50"; null stays null (no limit). */
    public static function minorToPounds(?int $minor): ?string
    {
        return $minor === null ? null : intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
