<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Documents\StatementDocument;
use App\Domain\Documents\DocumentRenders;
use App\Filament\Support\MoneyFormatter;
use App\Jobs\RenderDocument;
use App\Models\AccountStatement;
use App\Models\Company;
use App\Models\DocumentRender;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 05.17 §2 — the account statement: a fixed **as-of** export for a range of
 * UK calendar days, with the cutoff instant captured when it is requested.
 *
 * Debt and spendable balance are kept apart, as 05.2 §18 keeps them:
 *
 *   debt     opening debt + invoices issued − cash allocated − credit notes
 *            allocated = closing debt, then its ageing at the period end;
 *   balance  opening balance + ledger movements = closing balance;
 *   holds    on-account orders not yet invoiced, as at the cutoff.
 *
 * A credit note set against an invoice reduces debt once; it appears as
 * spendable balance only through the ledger's own movements, so it is
 * never counted twice.
 *
 * Every figure comes from **one SQL statement** — one snapshot at the
 * default READ COMMITTED isolation (CLAUDE.md invariant 7) — bounded by
 * the cutoff, so a payment posting meanwhile is either wholly in or wholly
 * out. The payload is stored as the statement's document render; later
 * postings never change an archived statement. Rendering happens off the
 * request, outside any transaction.
 */
final class Statements
{
    /** Days past due at the period end: label per bucket (as CreditOverview). */
    private const BUCKETS = [
        'current' => 'Not yet due',
        'days_1_30' => '1–30 days overdue',
        'days_31_60' => '31–60 days overdue',
        'days_61_90' => '61–90 days overdue',
        'days_90_plus' => 'Over 90 days overdue',
    ];

    public function __construct(private readonly DocumentRenders $renders) {}

    /**
     * @throws ValidationException
     */
    public function generate(Company $company, User $actor, string $fromOn, string $toOn): AccountStatement
    {
        $zone = DisplayTime::zone();
        $from = self::day($fromOn, $zone);
        $to = self::day($toOn, $zone);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $maxMonths = (int) config('documents.statement_max_months');

        if ($from === null || $to === null) {
            throw ValidationException::withMessages(['from_on' => 'Choose a start and end date.']);
        }
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to_on' => 'The end date must be on or after the start date.']);
        }
        if ($to->greaterThan($today)) {
            throw ValidationException::withMessages(['to_on' => 'The end date cannot be in the future.']);
        }
        if ($to->greaterThanOrEqualTo($from->addMonthsNoOverflow($maxMonths))) {
            throw ValidationException::withMessages(['to_on' => "A statement covers at most {$maxMonths} months. Request older periods separately."]);
        }

        $cutoff = CarbonImmutable::now()->utc();
        $payload = $this->payload($company, $from, $to, $cutoff);

        $statement = DB::transaction(function () use ($company, $actor, $from, $to, $cutoff, $payload): AccountStatement {
            $statement = AccountStatement::query()->create([
                'company_id' => $company->id,
                'from_on' => $from->toDateString(),
                'to_on' => $to->toDateString(),
                'cutoff_at' => $cutoff,
                'requested_by_user_id' => $actor->id,
                'requested_at' => $cutoff,
            ]);
            $this->renders->capture('statement', $statement->id, $company->id, new StatementDocument([
                'public_id' => $statement->public_id,
                ...$payload,
            ]), $actor->id);

            return $statement;
        });

        DB::afterCommit(function () use ($statement): void {
            $render = $this->renders->latest('statement', $statement->id);
            if ($render !== null) {
                RenderDocument::dispatch($render->id);
            }
        });

        return $statement;
    }

    /** The statement's stored figures (its render payload). */
    public function stored(AccountStatement $statement): ?DocumentRender
    {
        return $this->renders->latest('statement', $statement->id);
    }

    /**
     * The statement's figures, from one SQL snapshot.
     *
     * @return array<string, mixed>
     */
    public function payload(Company $company, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $cutoff): array
    {
        $startAt = $from->startOfDay()->utc();
        // Exclusive end: the instant the day after `to` begins in the UK, or the cutoff if earlier.
        $endAt = $to->addDay()->startOfDay()->utc();
        if ($cutoff->lessThan($endAt)) {
            $endAt = $cutoff;
        }

        $row = DB::selectOne(<<<'SQL'
WITH win AS (
  SELECT ?::bigint AS company_id, ?::timestamptz AS start_at, ?::timestamptz AS end_at, ?::timestamptz AS cutoff_at
),
inv AS (
  SELECT i.id, i.invoice_number, i.issued_at, i.due_at, i.total_gross_minor
  FROM invoices i, win
  WHERE i.company_id = win.company_id AND i.status <> 'void' AND i.issued_at < win.end_at
),
cash AS (
  SELECT pa.id, pa.invoice_id, pa.amount_minor, pa.allocated_at, inv.invoice_number
  FROM payment_allocations pa JOIN inv ON inv.id = pa.invoice_id, win
  WHERE pa.allocated_at < win.end_at
),
credits AS (
  SELECT cna.id, cna.invoice_id, cna.amount_minor, cna.allocated_at, inv.invoice_number, cn.credit_note_number
  FROM credit_note_allocations cna
  JOIN inv ON inv.id = cna.invoice_id
  JOIN credit_notes cn ON cn.id = cna.credit_note_id, win
  WHERE cna.allocated_at < win.end_at
),
outstanding AS (
  SELECT inv.id, inv.invoice_number, inv.issued_at, inv.due_at,
         inv.total_gross_minor
           - coalesce((SELECT sum(c.amount_minor) FROM cash c WHERE c.invoice_id = inv.id), 0)
           - coalesce((SELECT sum(k.amount_minor) FROM credits k WHERE k.invoice_id = inv.id), 0) AS outstanding_minor
  FROM inv
),
bal AS (
  SELECT m.id, m.occurred_at, m.movement_type, m.amount_minor,
         cn.credit_note_number, ri.invoice_number, o.order_number
  FROM account_credit_movements m
  LEFT JOIN credit_notes cn ON cn.id = m.credit_note_id
  LEFT JOIN invoices ri ON ri.id = m.reference_id AND m.reference_type = 'invoice'
  LEFT JOIN orders o ON o.id = m.order_id, win
  WHERE m.company_id = win.company_id AND m.occurred_at < win.end_at
)
SELECT json_build_object(
  'opening_invoiced', (SELECT coalesce(sum(total_gross_minor), 0) FROM inv, win WHERE issued_at < win.start_at),
  'opening_cash', (SELECT coalesce(sum(amount_minor), 0) FROM cash, win WHERE allocated_at < win.start_at),
  'opening_credits', (SELECT coalesce(sum(amount_minor), 0) FROM credits, win WHERE allocated_at < win.start_at),
  'invoices', (SELECT coalesce(json_agg(json_build_object(
      'number', invoice_number, 'at', issued_at, 'due_at', due_at, 'amount_minor', total_gross_minor)
      ORDER BY issued_at, id), '[]'::json) FROM inv, win WHERE issued_at >= win.start_at),
  'cash', (SELECT coalesce(json_agg(json_build_object(
      'invoice_number', invoice_number, 'at', allocated_at, 'amount_minor', amount_minor)
      ORDER BY allocated_at, id), '[]'::json) FROM cash, win WHERE allocated_at >= win.start_at),
  'credits', (SELECT coalesce(json_agg(json_build_object(
      'invoice_number', invoice_number, 'credit_note_number', credit_note_number, 'at', allocated_at, 'amount_minor', amount_minor)
      ORDER BY allocated_at, id), '[]'::json) FROM credits, win WHERE allocated_at >= win.start_at),
  'open_invoices', (SELECT coalesce(json_agg(json_build_object(
      'number', invoice_number, 'issued_at', issued_at, 'due_at', due_at, 'outstanding_minor', outstanding_minor)
      ORDER BY due_at NULLS LAST, id), '[]'::json) FROM outstanding WHERE outstanding_minor <> 0),
  'balance_opening', (SELECT coalesce(sum(amount_minor), 0) FROM bal, win WHERE occurred_at < win.start_at),
  'balance_movements', (SELECT coalesce(json_agg(json_build_object(
      'type', movement_type, 'at', occurred_at, 'amount_minor', amount_minor,
      'reference', coalesce(credit_note_number, invoice_number, order_number))
      ORDER BY occurred_at, id), '[]'::json) FROM bal, win WHERE occurred_at >= win.start_at),
  'holds', (SELECT coalesce(sum(h.amount_minor), 0) FROM credit_holds h, win
      WHERE h.company_id = win.company_id AND h.held_at < win.cutoff_at
        AND (h.status = 'held' OR (h.released_at IS NOT NULL AND h.released_at >= win.cutoff_at)))
) AS snapshot
SQL, [$company->id, $startAt->toIso8601String(), $endAt->toIso8601String(), $cutoff->toIso8601String()]);

        /** @var array<string, mixed> $s */
        $s = json_decode((string) $row->snapshot, true, flags: JSON_THROW_ON_ERROR);

        return $this->shape($company, $from, $to, $cutoff, $endAt, $s);
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function shape(Company $company, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $cutoff, CarbonImmutable $endAt, array $s): array
    {
        $int = static fn (mixed $v): int => (int) $v;
        $list = static fn (mixed $v): array => is_array($v) ? array_values($v) : [];
        $at = static fn (mixed $v): ?CarbonImmutable => is_string($v) && $v !== '' ? CarbonImmutable::parse($v)->utc() : null;
        $display = static fn (?CarbonImmutable $v): ?string => $v === null ? null : DisplayTime::format($v, DisplayTime::DATE);

        $invoices = array_map(fn (array $r): array => [
            'number' => (string) $r['number'],
            'at' => $at($r['at'])?->toIso8601ZuluString(),
            'date_display' => $display($at($r['at'])),
            'due_display' => $display($at($r['due_at'] ?? null)),
            ...self::money('amount', $int($r['amount_minor'])),
        ], $list($s['invoices'] ?? []));
        $cash = array_map(fn (array $r): array => [
            'invoice_number' => (string) $r['invoice_number'],
            'at' => $at($r['at'])?->toIso8601ZuluString(),
            'date_display' => $display($at($r['at'])),
            ...self::money('amount', $int($r['amount_minor'])),
        ], $list($s['cash'] ?? []));
        $credits = array_map(fn (array $r): array => [
            'invoice_number' => (string) $r['invoice_number'],
            'credit_note_number' => (string) $r['credit_note_number'],
            'at' => $at($r['at'])?->toIso8601ZuluString(),
            'date_display' => $display($at($r['at'])),
            ...self::money('amount', $int($r['amount_minor'])),
        ], $list($s['credits'] ?? []));
        $movements = array_map(fn (array $r): array => [
            'type' => (string) $r['type'],
            'type_label' => self::movementLabel((string) $r['type']),
            'reference' => is_string($r['reference'] ?? null) ? $r['reference'] : null,
            'at' => $at($r['at'])?->toIso8601ZuluString(),
            'date_display' => $display($at($r['at'])),
            ...self::money('amount', $int($r['amount_minor'])),
        ], $list($s['balance_movements'] ?? []));

        $openingDebt = $int($s['opening_invoiced'] ?? 0) - $int($s['opening_cash'] ?? 0) - $int($s['opening_credits'] ?? 0);
        $periodInvoiced = self::sum(array_column($invoices, 'amount_minor'));
        $periodCash = self::sum(array_column($cash, 'amount_minor'));
        $periodCredits = self::sum(array_column($credits, 'amount_minor'));
        $closingDebt = $openingDebt + $periodInvoiced - $periodCash - $periodCredits;

        $openingBalance = $int($s['balance_opening'] ?? 0);
        $periodBalance = self::sum(array_column($movements, 'amount_minor'));

        // Ageing at the period end, from each invoice's outstanding amount then.
        $ageing = array_fill_keys(array_keys(self::BUCKETS), ['count' => 0, 'amount_minor' => 0]);
        foreach ($list($s['open_invoices'] ?? []) as $open) {
            $due = $at($open['due_at'] ?? null);
            $days = $due === null || $due->greaterThanOrEqualTo($endAt) ? 0 : (int) $due->diffInDays($endAt);
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => 'days_1_30',
                $days <= 60 => 'days_31_60',
                $days <= 90 => 'days_61_90',
                default => 'days_90_plus',
            };
            $ageing[$bucket]['count']++;
            $ageing[$bucket]['amount_minor'] += $int($open['outstanding_minor']);
        }

        $code = (string) $company->account_code;

        return [
            'kind' => 'statement',
            'title' => 'Statement of account',
            'number' => "STATEMENT-{$code}-{$from->format('Ymd')}-{$to->format('Ymd')}",
            'from_on' => $from->toDateString(),
            'to_on' => $to->toDateString(),
            'from_display' => $from->format(DisplayTime::DATE),
            'to_display' => $to->format(DisplayTime::DATE),
            'cutoff_at' => $cutoff->toIso8601ZuluString(),
            'cutoff_display' => DisplayTime::format($cutoff),
            'seller' => self::seller(),
            'customer' => [
                'name' => $company->name,
                'account_code' => $code,
                'vat_number' => $company->vat_number,
                'payment_terms' => $company->payment_terms,
            ],
            'debt' => [
                ...self::money('opening', $openingDebt),
                ...self::money('invoiced', $periodInvoiced),
                ...self::money('cash', $periodCash),
                ...self::money('credits', $periodCredits),
                ...self::money('closing', $closingDebt),
                'invoices' => $invoices,
                'cash_allocations' => $cash,
                'credit_allocations' => $credits,
                'ageing' => array_map(fn (string $bucket): array => [
                    'bucket' => $bucket,
                    'label' => self::BUCKETS[$bucket],
                    'count' => $ageing[$bucket]['count'],
                    ...self::money('amount', $ageing[$bucket]['amount_minor']),
                ], array_keys(self::BUCKETS)),
            ],
            'balance' => [
                ...self::money('opening', $openingBalance),
                ...self::money('movements_total', $periodBalance),
                ...self::money('closing', $openingBalance + $periodBalance),
                'movements' => $movements,
            ],
            ...self::money('uninvoiced_holds', $int($s['holds'] ?? 0)),
            'footer' => [
                'Debt is what you owe on invoices; account balance is money held for you to spend. They are shown separately and never offset here.',
                'Figures are as at '.DisplayTime::format($cutoff).' (UK time). Payments recorded after that appear on your next statement.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function seller(): array
    {
        $seller = SellerDetails::fromConfiguration();

        return [
            'legal_name' => $seller->legalName,
            'address_lines' => $seller->addressLines,
            'vat_number' => $seller->vatNumber,
            'company_number' => $seller->companyNumber,
        ];
    }

    private static function movementLabel(string $type): string
    {
        return match ($type) {
            'credit_note' => 'Credit note',
            'applied_to_order' => 'Used on an order',
            'applied_to_invoice' => 'Set against an invoice',
            'refunded_to_bank' => 'Paid to your bank',
            'refunded_to_card' => 'Refunded to card',
            'payout_reserved' => 'Payout requested',
            'expiry' => 'Expired',
            'reversal' => 'Reversal',
            default => 'Adjustment',
        };
    }

    /** A UK calendar day exactly as given (`Y-m-d`), or null. */
    private static function day(string $value, string $zone): ?CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * Integer pence, summed as integers (CLAUDE.md invariant 1).
     *
     * @param  array<int|string, mixed>  $values
     */
    private static function sum(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $total += (int) $value;
        }

        return $total;
    }

    /** @return array<string, int|string|null> */
    private static function money(string $name, int $minor): array
    {
        return ["{$name}_minor" => $minor, $name => MoneyFormatter::minor($minor)];
    }
}
