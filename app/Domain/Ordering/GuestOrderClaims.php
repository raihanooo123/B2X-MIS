<?php

namespace App\Domain\Ordering;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * 05.15 §6.3 — once a user's email is verified, every guest order placed
 * with that email and not yet claimed is attached to them (`user_id`
 * set; `guest_email` kept as the record of how it was placed, 02 §26.1),
 * each audited as `order.claimed`.
 *
 * Only on verification: an unverified address claims nothing, so no-one
 * can collect another person's order history by registering their email.
 * Served by `orders_guest_claim_idx`. A trade order (company set) is never
 * a guest order and is never touched.
 */
final class GuestOrderClaims
{
    /**
     * @return list<int> the ids of the orders claimed
     */
    public function claimFor(User $user): array
    {
        if (! $user->hasVerifiedEmail()) {
            return [];
        }

        return DB::transaction(function () use ($user): array {
            $rows = DB::select(
                <<<'SQL'
                    UPDATE orders SET user_id = ?, updated_at = now()
                    WHERE  lower(guest_email) = ?
                      AND  user_id IS NULL
                      AND  company_id IS NULL
                      AND  guest_email IS NOT NULL
                    RETURNING id
                    SQL,
                [$user->id, mb_strtolower(trim($user->email))],
            );

            $ids = array_map(fn (stdClass $row): int => (int) $row->id, $rows);
            sort($ids);

            foreach ($ids as $orderId) {
                (new AuditLogger)->record(new AuditEntry(
                    action: AuditAction::OrderClaimed,
                    actorType: 'user',
                    actorUserId: $user->id,
                    subjectType: 'order',
                    subjectId: $orderId,
                    before: ['user_id' => null],
                    after: ['user_id' => $user->id],
                ));
            }

            return $ids;
        });
    }
}
