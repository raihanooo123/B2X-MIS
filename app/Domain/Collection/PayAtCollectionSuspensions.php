<?php

namespace App\Domain\Collection;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Ordering\PaymentMethod;
use App\Models\Order;
use App\Models\PayAtCollectionSuspension;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §7A.11 — no-shows and suspension of pay at collection.
 *
 * Counted: the customer's `no_show` bookings on `cash_at_collection`
 * orders, with `no_show_at` inside the last `no_show_window_days` and after
 * the customer's last lifted suspension. A trade customer is counted per
 * company (Q-C2), a public customer per user. Prepaid no-shows don't count.
 * The count starts from the owner's orders (`orders_company_placed_idx`,
 * `orders_public_user_placed_idx`) and reaches each booking by
 * `collection_bookings_order_uq`: no new index.
 *
 * At the limit, the expiry sweep inserts an automatic suspension in its
 * own transaction; `ON CONFLICT DO NOTHING` on the active-suspension index
 * makes a second insert harmless. Staff with `accounts` lift it (counting
 * restarts from the lift) or suspend by hand, always with a reason, audited.
 */
final class PayAtCollectionSuspensions
{
    public function __construct(
        private readonly CollectionSettings $settings = new CollectionSettings,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function noShowCount(?int $userId, ?int $companyId, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDays($this->settings->noShowWindowDays());
        $lifted = DB::table('pay_at_collection_suspensions')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->where('user_id', $userId))
            ->max('lifted_at');
        if ($lifted !== null && CarbonImmutable::parse((string) $lifted)->greaterThan($since)) {
            $since = CarbonImmutable::parse((string) $lifted);
        }

        return DB::table('orders')
            ->join('collection_bookings', 'collection_bookings.order_id', '=', 'orders.id')
            ->tap(fn (Builder $q) => $this->ownedBy($q, $userId, $companyId))
            ->where('orders.payment_method', PaymentMethod::CashAtCollection->value)
            ->where('collection_bookings.status', 'no_show')
            ->where('collection_bookings.no_show_at', '>', $since)
            ->count();
    }

    /**
     * Inside the sweep's transaction, after the booking became `no_show`.
     *
     * @return array{id: int, count: int}|null the suspension inserted, if the limit was reached just now
     */
    public function afterNoShow(Order $order, ?CarbonImmutable $now = null): ?array
    {
        $userId = $order->company_id === null ? $order->user_id : null;
        $companyId = $order->company_id;
        if ($userId === null && $companyId === null) {
            return null;
        }

        $count = $this->noShowCount($userId, $companyId, $now);
        if ($count < $this->settings->noShowLimit()) {
            return null;
        }

        $owner = $companyId !== null ? 'company_id' : 'user_id';
        $row = DB::selectOne(
            "INSERT INTO pay_at_collection_suspensions ({$owner}, reason, no_show_count, suspended_at)
             VALUES (?, 'no_shows', ?, ?)
             ON CONFLICT ({$owner}) WHERE {$owner} IS NOT NULL AND lifted_at IS NULL DO NOTHING
             RETURNING id",
            [$companyId ?? $userId, $count, ($now ?? CarbonImmutable::now())->toIso8601String()],
        );

        return $row === null ? null : ['id' => (int) $row->id, 'count' => $count];
    }

    /**
     * @throws CashRefused when the customer is already suspended
     */
    public function suspend(?int $userId, ?int $companyId, int $staffUserId, string $note): PayAtCollectionSuspension
    {
        $note = trim($note);
        if (($userId === null) === ($companyId === null) || $note === '') {
            throw new CashRefused('invalid_suspension', 'Choose one customer, a person or a company, and give a reason.');
        }

        return DB::transaction(function () use ($userId, $companyId, $staffUserId, $note) {
            $active = PayAtCollectionSuspension::query()->whereNull('lifted_at')
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->where('user_id', $userId))
                ->lockForUpdate()->exists();
            if ($active) {
                throw new CashRefused('already_suspended', 'Pay at collection is already suspended for this customer.', 409);
            }

            $suspension = PayAtCollectionSuspension::query()->create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'reason' => 'manual',
                'suspended_at' => now(),
                'suspended_by_user_id' => $staffUserId,
                'note' => $note,
            ]);
            $this->audit->record(new AuditEntry(
                action: AuditAction::PayAtCollectionSuspended,
                actorType: 'user',
                actorUserId: $staffUserId,
                companyId: $companyId,
                subjectType: 'pay_at_collection_suspension',
                subjectId: $suspension->id,
                after: ['user_id' => $userId, 'company_id' => $companyId],
                reason: $note,
            ));

            return $suspension;
        });
    }

    /**
     * @throws CashRefused when the suspension is already lifted
     */
    public function lift(int $suspensionId, int $staffUserId, string $reason): PayAtCollectionSuspension
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new CashRefused('reason_required', 'Give a reason for lifting the suspension.');
        }

        return DB::transaction(function () use ($suspensionId, $staffUserId, $reason) {
            $suspension = PayAtCollectionSuspension::query()->where('id', $suspensionId)->lockForUpdate()->firstOrFail();
            if ($suspension->lifted_at !== null) {
                throw new CashRefused('already_lifted', 'This suspension has already been lifted.', 409);
            }
            $liftedAt = now();
            $suspension->forceFill(['lifted_at' => $liftedAt, 'lifted_by_user_id' => $staffUserId, 'lift_reason' => $reason])->save();

            $this->audit->record(new AuditEntry(
                action: AuditAction::PayAtCollectionReinstated,
                actorType: 'user',
                actorUserId: $staffUserId,
                companyId: $suspension->company_id,
                subjectType: 'pay_at_collection_suspension',
                subjectId: $suspension->id,
                before: ['lifted_at' => null],
                after: ['lifted_at' => $liftedAt->toIso8601String()],
                reason: $reason,
            ));

            return $suspension;
        });
    }

    private function ownedBy(Builder $query, ?int $userId, ?int $companyId): void
    {
        // Matches the partial indexes' predicates, so the planner can use them.
        $query->whereNotNull('orders.placed_at');
        if ($companyId !== null) {
            $query->where('orders.company_id', $companyId);

            return;
        }
        $query->whereNull('orders.company_id')->where('orders.user_id', $userId);
    }
}
