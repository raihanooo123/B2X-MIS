<?php

namespace App\Domain\Collection;

use App\Domain\Audit\AuditAction;
use App\Filament\Support\MoneyFormatter;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 05.6 §7A.6a — the end-of-day cash-up for one location and one UK
 * calendar day (Europe/London midnight to midnight, so a DST day is 23 or
 * 25 hours).
 *
 *   - per staff member (`recorded_by_user_id`): the cash payments they
 *     recorded that day, their gross total, and the count and total of
 *     their payments voided that day;
 *   - the day: cash recorded minus voids, then cash refunds paid at the
 *     counter subtracted for the net the drawer should hold;
 *   - the detail: every cash payment, void and refund of the day, newest
 *     first, with who recorded it and who voided it and why.
 *
 * Source: `payments` with `gateway = 'cash'` (types `payment` and
 * `refund`), joined to the order and its booking's slot for the location,
 * filtered on `captured_at`; voids on their audit time (`payment.cash_voided`).
 * Nothing is stored, so the report can't drift. Integer totals only.
 *
 * @phpstan-type CashReportLine array{kind: string, at: string, order_number: string, order_id: string, customer: string, amount_minor: int, recorded_by: string|null, voided_by: string|null, void_reason: string|null}
 * @phpstan-type CashReport array{
 *     location_id: int, day: string, from: string, to: string,
 *     staff: list<array{user_id: int|null, name: string, payments: int, gross_minor: int, voids: int, voided_minor: int}>,
 *     recorded_minor: int, voided_minor: int, refunded_minor: int, net_minor: int,
 *     detail: list<CashReportLine>
 * }
 */
final class DailyCashReport
{
    /**
     * @return CashReport
     */
    public function build(int $locationId, CarbonImmutable $day): array
    {
        $from = CarbonImmutable::parse($day->format('Y-m-d'), CollectionSlots::ZONE)->startOfDay();
        $to = $from->addDay();
        [$fromUtc, $toUtc] = [$from->utc(), $to->utc()];

        $taken = $this->cashRows($locationId)
            ->where('payments.captured_at', '>=', $fromUtc)->where('payments.captured_at', '<', $toUtc)
            ->get();
        $voids = $this->cashRows($locationId)
            ->join('audit_log', fn ($j) => $j->on('audit_log.subject_id', '=', 'payments.id')
                ->where('audit_log.subject_type', 'payment')
                ->where('audit_log.action', AuditAction::PaymentCashVoided->value))
            ->where('audit_log.occurred_at', '>=', $fromUtc)->where('audit_log.occurred_at', '<', $toUtc)
            ->addSelect(['audit_log.occurred_at AS voided_at', 'audit_log.actor_user_id AS voided_by_user_id', 'audit_log.reason AS void_reason'])
            ->get();

        $names = $this->names(array_values(array_merge(
            $taken->pluck('recorded_by_user_id')->all(),
            $voids->pluck('recorded_by_user_id')->all(),
            $voids->pluck('voided_by_user_id')->all(),
        )));

        $staff = [];
        $recorded = 0;
        $refunded = 0;
        $detail = [];
        foreach ($taken as $row) {
            $amount = (int) $row->amount_minor;
            if ($row->type === 'refund') {
                $refunded += $amount;
            } else {
                $recorded += $amount;
                $entry = $staff[$row->recorded_by_user_id] ??= self::staffRow($row->recorded_by_user_id, $names);
                $entry['payments']++;
                $entry['gross_minor'] += $amount;
                $staff[$row->recorded_by_user_id] = $entry;
            }
            $detail[] = $this->detailRow($row->type === 'refund' ? 'refund' : 'payment', (string) $row->captured_at, $row, $names);
        }

        $voided = 0;
        foreach ($voids as $row) {
            $amount = (int) $row->amount_minor;
            $voided += $amount;
            $entry = $staff[$row->recorded_by_user_id] ??= self::staffRow($row->recorded_by_user_id, $names);
            $entry['voids']++;
            $entry['voided_minor'] += $amount;
            $staff[$row->recorded_by_user_id] = $entry;
            $detail[] = $this->detailRow('void', (string) $row->voided_at, $row, $names);
        }

        usort($detail, fn (array $a, array $b) => strcmp($b['at'], $a['at']));
        $staffRows = array_values($staff);
        usort($staffRows, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return [
            'location_id' => $locationId,
            'day' => $from->toDateString(),
            'from' => $fromUtc->toIso8601String(),
            'to' => $toUtc->toIso8601String(),
            'staff' => $staffRows,
            'recorded_minor' => $recorded,
            'voided_minor' => $voided,
            'refunded_minor' => $refunded,
            'net_minor' => $recorded - $voided - $refunded,
            'detail' => $detail,
        ];
    }

    /**
     * The detail, for the cash-up sheet. Amounts as decimal strings, from
     * integer pence (invariant 1); times in UK time.
     *
     * @param  CashReport  $report
     * @return list<list<string>>
     */
    public static function csvRows(array $report): array
    {
        $rows = [['Time (UK)', 'Entry', 'Order', 'Customer', 'Amount (GBP)', 'Recorded by', 'Voided by', 'Void reason']];
        foreach ($report['detail'] as $line) {
            $signed = $line['kind'] === 'payment' ? $line['amount_minor'] : -$line['amount_minor'];
            $rows[] = [
                DisplayTime::format(CarbonImmutable::parse($line['at']), 'Y-m-d H:i:s'),
                $line['kind'],
                $line['order_number'],
                $line['customer'],
                (string) MoneyFormatter::minorToDecimalString($signed),
                $line['recorded_by'] ?? '',
                $line['voided_by'] ?? '',
                $line['void_reason'] ?? '',
            ];
        }

        return $rows;
    }

    private function cashRows(int $locationId): Builder
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('collection_bookings', 'collection_bookings.order_id', '=', 'orders.id')
            ->join('collection_slots', 'collection_slots.id', '=', 'collection_bookings.collection_slot_id')
            ->leftJoin('users AS customer', 'customer.id', '=', 'orders.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'orders.company_id')
            ->where('payments.gateway', 'cash')
            ->whereIn('payments.type', ['payment', 'refund'])
            ->where('collection_slots.location_id', $locationId)
            ->select([
                'payments.id', 'payments.type', 'payments.amount_minor', 'payments.captured_at', 'payments.recorded_by_user_id',
                'orders.order_number', 'orders.public_id AS order_public_id', 'orders.guest_email',
                'companies.name AS company_name', 'customer.first_name', 'customer.last_name',
            ]);
    }

    /**
     * @param  array<int, string>  $names
     * @return CashReportLine
     */
    private function detailRow(string $kind, string $at, \stdClass $row, array $names): array
    {
        $customer = $row->company_name ?? trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return [
            'kind' => $kind,
            'at' => CarbonImmutable::parse($at)->toIso8601String(),
            'order_number' => (string) $row->order_number,
            'order_id' => (string) $row->order_public_id,
            'customer' => $customer !== '' ? (string) $customer : (string) ($row->guest_email ?? ''),
            'amount_minor' => (int) $row->amount_minor,
            'recorded_by' => $row->recorded_by_user_id === null ? null : ($names[(int) $row->recorded_by_user_id] ?? null),
            'voided_by' => $kind === 'void' && isset($row->voided_by_user_id) ? ($names[(int) $row->voided_by_user_id] ?? null) : null,
            'void_reason' => $kind === 'void' ? ($row->void_reason ?? null) : null,
        ];
    }

    /**
     * @param  array<int, string>  $names
     * @return array{user_id: int|null, name: string, payments: int, gross_minor: int, voids: int, voided_minor: int}
     */
    private static function staffRow(mixed $userId, array $names): array
    {
        $id = $userId === null ? null : (int) $userId;

        return ['user_id' => $id, 'name' => $id === null ? 'Unknown' : ($names[$id] ?? "User {$id}"), 'payments' => 0, 'gross_minor' => 0, 'voids' => 0, 'voided_minor' => 0];
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (int) $id, $ids))));
        if ($ids === []) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (object $u) => [(int) $u->id => trim("{$u->first_name} {$u->last_name}")])
            ->all();
    }
}
