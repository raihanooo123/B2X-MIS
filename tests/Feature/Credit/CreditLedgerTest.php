<?php

use App\Domain\Credit\ApprovalKind;
use App\Domain\Credit\ApprovalStatus;
use App\Domain\Credit\CreditLedger;
use App\Domain\Credit\CreditMovementType;
use App\Domain\Credit\CreditPayoutStatus;
use App\Domain\Credit\CreditReconciliation;
use App\Domain\Credit\CreditRefused;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 02 §31.1, §31.8, 05.4 §15.3 — the account-credit ledger: settlement
 * without double benefit, global idempotency, append-only history, and
 * the new constraints on live tables.
 */
uses(RefreshDatabase::class);

function clgInvoice(Company $company, int $gross, string $method = 'on_account', int $paid = 0): Invoice
{
    $order = Order::factory()->create(['company_id' => $company->id, 'payment_method' => $method, 'payment_status' => $method === 'on_account' ? 'on_account' : 'unpaid']);

    return Invoice::factory()->create([
        'company_id' => $company->id, 'order_id' => $order->id, 'status' => $paid >= $gross ? 'paid' : ($paid > 0 ? 'part_paid' : 'issued'),
        'total_gross_minor' => $gross, 'paid_minor' => $paid, 'due_at' => now()->addDays(30),
    ]);
}

function clgNote(Company $company, Invoice $invoice, int $gross): CreditNote
{
    return CreditNote::query()->create([
        'credit_note_number' => 'CN-'.Str::upper(Str::random(8)), 'company_id' => $company->id, 'order_id' => $invoice->order_id,
        'invoice_id' => $invoice->id, 'reason' => 'return', 'subtotal_net_minor' => intdiv($gross * 5, 6), 'tax_minor' => $gross - intdiv($gross * 5, 6), 'total_gross_minor' => $gross,
    ]);
}

/** @return list<array{type: string, amount: int, after: int}> */
function clgMovements(Company $company): array
{
    return DB::table('account_credit_movements')->where('company_id', $company->id)->orderBy('id')->get()
        ->map(fn (object $m): array => ['type' => (string) $m->movement_type, 'amount' => (int) $m->amount_minor, 'after' => (int) $m->balance_after_minor])->values()->all();
}

it('settles £120 against £100 unpaid: £100 extinguishes the debt, only £20 becomes balance (05.4 §15.3)', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_limit_minor' => 100_000, 'credit_used_minor' => 10_000]);
    $invoice = clgInvoice($company, 10_000);
    $note = clgNote($company, $invoice, 12_000);

    (new CreditLedger)->settleCreditNote($note->id);

    expect(clgMovements($company))->toBe([
        ['type' => 'credit_note', 'amount' => 12_000, 'after' => 12_000],
        ['type' => 'applied_to_invoice', 'amount' => -10_000, 'after' => 2_000],
    ])
        ->and($company->fresh()->account_balance_minor)->toBe(2_000)
        ->and($company->fresh()->credit_used_minor)->toBe(0)
        ->and($invoice->fresh()->credited_minor)->toBe(10_000)
        ->and($invoice->fresh()->paid_minor)->toBe(0)
        ->and($invoice->fresh()->status)->toBe('credited')
        ->and((int) DB::table('credit_note_allocations')->where('credit_note_id', $note->id)->sum('amount_minor'))->toBe(10_000);
});

it('makes the whole note balance when the goods were already paid for', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30']);
    $invoice = clgInvoice($company, 10_000, 'bacs', 10_000);
    $note = clgNote($company, $invoice, 4_000);

    (new CreditLedger)->settleCreditNote($note->id);

    expect($company->fresh()->account_balance_minor)->toBe(4_000)
        ->and(clgMovements($company))->toBe([['type' => 'credit_note', 'amount' => 4_000, 'after' => 4_000]])
        ->and($invoice->fresh()->credited_minor)->toBe(0);
});

it('settles each credit note once, however often it is replayed', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_used_minor' => 10_000]);
    $note = clgNote($company, clgInvoice($company, 10_000), 12_000);

    (new CreditLedger)->settleCreditNote($note->id);
    (new CreditLedger)->settleCreditNote($note->id);

    expect(DB::table('account_credit_events')->where('event_key', "credit-note:{$note->id}")->count())->toBe(1)
        ->and(count(clgMovements($company)))->toBe(2)
        ->and($company->fresh()->account_balance_minor)->toBe(2_000);
});

it('never settles a consumer credit note to an account balance', function () {
    $order = Order::factory()->create(['company_id' => null]);
    $note = CreditNote::query()->create(['credit_note_number' => 'CN-CONSUMER1', 'order_id' => $order->id, 'reason' => 'return', 'total_gross_minor' => 500]);

    expect(fn () => (new CreditLedger)->settleCreditNote($note->id))->toThrow(InvalidArgumentException::class);
});

it('refuses a replay with different details, and a movement that would make the balance negative', function () {
    $company = Company::factory()->create();

    DB::transaction(fn () => (new CreditLedger)->append($company, 'adjustment:1', [['type' => CreditMovementType::Adjustment, 'amount' => 500, 'reason' => 'opening']]));

    $refusal = function (Closure $write): ?string {
        try {
            DB::transaction($write);
        } catch (CreditRefused $e) {
            return $e->reason;
        }

        return null;
    };

    expect($refusal(fn () => (new CreditLedger)->append($company, 'adjustment:1', [['type' => CreditMovementType::Adjustment, 'amount' => 900, 'reason' => 'opening']])))->toBe('event_conflict')
        ->and($refusal(fn () => (new CreditLedger)->append($company, 'payout:x', [['type' => CreditMovementType::PayoutReserved, 'amount' => -501]])))->toBe('balance_unavailable')
        ->and($company->fresh()->account_balance_minor)->toBe(500);
});

it('rejects UPDATE, DELETE and TRUNCATE on the ledger, its events and allocations (02 §31.8)', function () {
    $company = Company::factory()->create(['credit_used_minor' => 10_000]);
    (new CreditLedger)->settleCreditNote(clgNote($company, clgInvoice($company, 10_000), 12_000)->id);

    foreach (['account_credit_movements' => 'amount_minor', 'account_credit_events' => 'event_kind', 'credit_note_allocations' => 'amount_minor'] as $table => $column) {
        $id = DB::table($table)->min('id');
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update([$column => $column === 'event_kind' ? 'changed' : 1])))
            ->toThrow(QueryException::class, 'B2B history is append-only');
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(QueryException::class, 'B2B history is append-only');
        expect(fn () => DB::transaction(fn () => DB::statement("TRUNCATE {$table} CASCADE")))->toThrow(QueryException::class, 'B2B history is append-only');
    }
    // The partitions refuse truncation directly too.
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE account_credit_movements_2026')))->toThrow(QueryException::class, 'B2B history is append-only');

    expect(DB::table('account_credit_movements')->where('company_id', $company->id)->count())->toBe(2);
});

it('enforces the new 02 §31.1 constraints', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $order = Order::factory()->create(['company_id' => $company->id, 'user_id' => $user->id]);
    $request = fn (array $overrides = []): array => $overrides + [
        'public_id' => (string) Str::ulid(), 'company_id' => $company->id, 'order_id' => $order->id, 'requested_by_user_id' => $user->id,
        'approval_kind' => 'buyer_limit', 'status' => 'pending', 'order_gross_minor' => 100,
        'requested_at' => now(), 'expires_at' => now()->addHours(48),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('company_users')->insert(['company_id' => $company->id, 'user_id' => User::factory()->create()->id, 'role' => 'buyer', 'order_limit_minor' => -1])))
        ->toThrow(QueryException::class, 'company_users_order_limit_nonnegative_chk');
    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', Invoice::factory()->create(['company_id' => $company->id])->id)->update(['credited_minor' => -1])))
        ->toThrow(QueryException::class, 'invoices_credited_nonnegative_chk');

    DB::table('order_approval_requests')->insert($request());
    expect(fn () => DB::transaction(fn () => DB::table('order_approval_requests')->insert($request())))->toThrow(QueryException::class, 'order_approval_requests_order_id_approval_kind_key');
    expect(fn () => DB::transaction(fn () => DB::table('order_approval_requests')->insert($request(['approval_kind' => 'credit_exception', 'status' => 'approved']))))
        ->toThrow(QueryException::class, 'order_approval_requests_check1');
    expect(fn () => DB::transaction(fn () => DB::table('order_approval_requests')->insert($request(['approval_kind' => 'credit_exception', 'expires_at' => now()->subMinute()]))))
        ->toThrow(QueryException::class, 'order_approval_requests_check');

    expect(fn () => DB::transaction(fn () => DB::table('account_credit_payouts')->insert([
        'public_id' => (string) Str::ulid(), 'company_id' => $company->id, 'event_key' => 'payout:x', 'method' => 'original_card',
        'amount_minor' => 100, 'destination_reference' => 'payment:x', 'requested_by_user_id' => $user->id,
    ])))->toThrow(QueryException::class, 'account_credit_payouts_check');
});

it('keeps each credit enum in step with its CHECK constraint (02 §2.5)', function (string $constraint, string $enum) {
    $definition = (string) DB::table('pg_constraint')->where('conname', $constraint)
        ->selectRaw('pg_get_constraintdef(oid) AS def')->value('def');
    preg_match_all("/'([a-z_]+)'::text/", $definition, $matches);

    $values = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());
    sort($values);
    $allowed = array_values(array_unique($matches[1]));
    sort($allowed);

    expect($definition)->not->toBe('')
        ->and($allowed)->toBe($values);
})->with([
    'approval kind' => ['order_approval_requests_approval_kind_check', ApprovalKind::class],
    'approval status' => ['order_approval_requests_status_check', ApprovalStatus::class],
    'movement type' => ['account_credit_movements_type_chk', CreditMovementType::class],
    'payout status' => ['account_credit_payouts_status_check', CreditPayoutStatus::class],
]);

it('flags credit projection drift hourly without repairing it (05.2 §18.1)', function () {
    $company = Company::factory()->create(['payment_terms' => 'net30', 'credit_used_minor' => 10_000]);
    $note = clgNote($company, clgInvoice($company, 10_000), 12_000);
    (new CreditLedger)->settleCreditNote($note->id);

    expect(collect((new CreditReconciliation)->drift())->where('company_id', $company->id)->all())->toBe([]);

    DB::table('companies')->where('id', $company->id)->update(['account_balance_minor' => 9_999]);

    expect(array_values(collect((new CreditReconciliation)->drift())->where('company_id', $company->id)->all()))->toBe([
        ['company_id' => $company->id, 'field' => 'account_balance_minor', 'stored' => 9_999, 'rebuilt' => 2_000],
    ])
        ->and($company->fresh()->account_balance_minor)->toBe(9_999);
});
