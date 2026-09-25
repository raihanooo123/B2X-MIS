<?php

namespace App\Domain\Inventory;

/**
 * Who moved stock, and why, carried onto every `stock_movements` row a
 * service writes (`actor_user_id`, `reason_code`, `note` — 02 §7.4).
 *
 * Until the audit log exists (02 §15, ROADMAP §0.6), this is the record
 * of a privileged stock action such as a batch substitution (05.5 §5.3,
 * decided 2026-09-25): the ledger itself says who did it and why.
 */
final class MovementAttribution
{
    public function __construct(
        public readonly ?int $actorUserId,
        public readonly ?string $reasonCode = null,
        public readonly ?string $note = null,
    ) {}

    /**
     * @return array{actor_user_id: ?int, reason_code: ?string, note: ?string}
     */
    public function columns(): array
    {
        return ['actor_user_id' => $this->actorUserId, 'reason_code' => $this->reasonCode, 'note' => $this->note];
    }

    /**
     * @return array{actor_user_id: ?int, reason_code: ?string, note: ?string}
     */
    public static function columnsOf(?self $attribution): array
    {
        return $attribution?->columns() ?? ['actor_user_id' => null, 'reason_code' => null, 'note' => null];
    }
}
