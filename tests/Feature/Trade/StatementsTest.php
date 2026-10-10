<?php

use App\Domain\Billing\Statements;
use App\Models\AccountStatement;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditHold;
use App\Models\CreditNote;
use App\Models\DocumentRender;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 05.17 §2 — statements: a fixed as-of export; debt (opening, invoices,
 * cash, credit notes, closing, ageing) apart from spendable balance; a
 * credit note set against an invoice never counted twice; out-of-order
 * events; one snapshot bounded by the cutoff, unchanged by later
 * postings; UK calendar days across BST; the 12-month window.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function stmCompany(): array
{
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);

    return [$company, $owner];
}

function stmInvoice(Company $company, string $issuedAt, int $gross, array $overrides = []): Invoice
{
    return Invoice::factory()->create($overrides + [
        'company_id' => $company->id,
        'order_id' => Order::factory()->create(['company_id' => $company->id])->id,
        'invoice_number' => 'INV-'.Str::upper(Str::random(6)),
        'status' => 'issued',
        'issued_at' => CarbonImmutable::parse($issuedAt, 'UTC'),
        'due_at' => CarbonImmutable::parse($issuedAt, 'UTC')->addDays(30),
        'total_gross_minor' => $gross,
    ]);
}

function stmCash(Invoice $invoice, int $amount, string $at): void
{
    PaymentAllocation::factory()->create([
        'payment_id' => Payment::factory()->create(['company_id' => $invoice->company_id, 'amount_minor' => abs($amount)])->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => $amount,
        // A negative allocation is a reversal, which must carry a reason (payment_allocations_reason_chk).
        'reason_code' => $amount < 0 ? 'reversal' : null,
        'allocated_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
}

function stmEvent(Company $company, string $at): int
{
    return (int) DB::table('account_credit_events')->insertGetId([
        'event_key' => 'stm:'.Str::ulid(), 'company_id' => $company->id, 'event_kind' => 'credit_note',
        'payload_hash' => str_repeat('a', 64), 'occurred_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
}

/** A credit note: `allocated` set against the invoice's debt, the rest to spendable balance. */
function stmCreditNote(Invoice $invoice, int $gross, int $allocated, string $at): CreditNote
{
    $company = Company::query()->findOrFail($invoice->company_id);
    $note = CreditNote::query()->create([
        'credit_note_number' => 'CN-'.Str::upper(Str::random(6)), 'company_id' => $company->id, 'invoice_id' => $invoice->id,
        'reason' => 'return', 'total_gross_minor' => $gross, 'issued_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
    $event = stmEvent($company, $at);
    if ($allocated > 0) {
        DB::table('credit_note_allocations')->insert(['credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'event_id' => $event,
            'amount_minor' => $allocated, 'allocated_at' => CarbonImmutable::parse($at, 'UTC')]);
    }
    if ($gross > $allocated) {
        DB::table('account_credit_movements')->insert(['event_id' => $event, 'entry_no' => 1, 'company_id' => $company->id,
            'occurred_at' => CarbonImmutable::parse($at, 'UTC'), 'movement_type' => 'credit_note', 'amount_minor' => $gross - $allocated, 'credit_note_id' => $note->id]);
    }

    return $note;
}

function stmPayload(AccountStatement $statement): array
{
    return DocumentRender::query()->where('document_type', 'statement')->where('source_id', $statement->id)->sole()->payload;
}

it('keeps debt and spendable balance apart and never counts an allocated credit note twice', function () {
    [$company, $owner] = stmCompany();
    $before = stmInvoice($company, '2026-09-10 10:00:00', 5_000);
    $inPeriod = stmInvoice($company, '2026-10-03 10:00:00', 20_000);
    stmCash($before, 2_000, '2026-09-20 10:00:00');
    stmCash($inPeriod, 8_000, '2026-10-05 10:00:00');
    // £30 credit: £10 against the October invoice's debt, £20 to balance.
    stmCreditNote($inPeriod, 3_000, 1_000, '2026-10-06 10:00:00');

    $statement = app(Statements::class)->generate($company, $owner, '2026-10-01', '2026-10-19');
    $p = stmPayload($statement);

    expect($p['debt']['opening_minor'])->toBe(3_000)
        ->and($p['debt']['invoiced_minor'])->toBe(20_000)
        ->and($p['debt']['cash_minor'])->toBe(8_000)
        ->and($p['debt']['credits_minor'])->toBe(1_000)
        ->and($p['debt']['closing_minor'])->toBe(14_000)
        ->and($p['balance']['opening_minor'])->toBe(0)
        ->and($p['balance']['closing_minor'])->toBe(2_000)
        ->and(array_column($p['balance']['movements'], 'amount_minor'))->toBe([2_000])
        // Debt credit + balance credit = the note, once.
        ->and($p['debt']['credits_minor'] + $p['balance']['movements_total_minor'])->toBe(3_000)
        ->and($p['debt']['closing'])->toBe('£140.00')
        ->and(array_sum(array_column($p['debt']['ageing'], 'amount_minor')))->toBe(14_000);
});

it('applies events by when they were posted, whatever order they arrive in', function () {
    [$company, $owner] = stmCompany();
    $invoice = stmInvoice($company, '2026-10-02 10:00:00', 10_000);
    // Inserted out of time order: the later payment first, then the earlier one and a reversal.
    stmCash($invoice, 3_000, '2026-10-15 10:00:00');
    stmCash($invoice, 4_000, '2026-10-04 10:00:00');
    stmCash($invoice, -1_000, '2026-10-16 09:00:00');

    $p = stmPayload(app(Statements::class)->generate($company, $owner, '2026-10-01', '2026-10-19'));

    expect(array_column($p['debt']['cash_allocations'], 'amount_minor'))->toBe([4_000, 3_000, -1_000])
        ->and($p['debt']['cash_minor'])->toBe(6_000)
        ->and($p['debt']['closing_minor'])->toBe(4_000);
});

it('fixes the figures at the cutoff: a payment posted afterwards changes neither that statement nor its archive', function () {
    [$company, $owner] = stmCompany();
    $invoice = stmInvoice($company, '2026-10-02 10:00:00', 10_000);
    $statement = app(Statements::class)->generate($company, $owner, '2026-10-01', '2026-10-20');
    $first = stmPayload($statement);

    // Posted after the cutoff, with a timestamp inside the period's last day.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 13:00:00', 'UTC'));
    stmCash($invoice, 10_000, '2026-10-20 12:30:00');

    expect(stmPayload($statement))->toBe($first)
        ->and($first['debt']['closing_minor'])->toBe(10_000)
        ->and($first['cutoff_at'])->toBe('2026-10-20T12:00:00Z');

    // A statement asked for now sees it.
    $later = stmPayload(app(Statements::class)->generate($company, $owner, '2026-10-01', '2026-10-20'));
    expect($later['debt']['closing_minor'])->toBe(0);

    expect(fn () => DB::transaction(fn () => DB::table('account_statements')->where('id', $statement->id)->update(['to_on' => '2026-10-19'])))
        ->toThrow(QueryException::class, 'immutable');
});

it('counts UK calendar days: 23:30 UTC on 30 September is October in London', function () {
    [$company, $owner] = stmCompany();
    stmInvoice($company, '2026-09-30 23:30:00', 7_000);
    stmInvoice($company, '2026-09-30 22:30:00', 1_000);

    $october = stmPayload(app(Statements::class)->generate($company, $owner, '2026-10-01', '2026-10-19'));
    $september = stmPayload(app(Statements::class)->generate($company, $owner, '2026-09-01', '2026-09-30'));

    expect($october['debt']['invoiced_minor'])->toBe(7_000)
        ->and($october['debt']['opening_minor'])->toBe(1_000)
        ->and($september['debt']['invoiced_minor'])->toBe(1_000);
});

it('shows on-account orders not yet invoiced as at the cutoff', function () {
    [$company, $owner] = stmCompany();
    CreditHold::factory()->create(['company_id' => $company->id, 'order_id' => Order::factory()->create(['company_id' => $company->id])->id, 'amount_minor' => 4_500, 'status' => 'held', 'held_at' => now()->subDay()]);

    $p = stmPayload(app(Statements::class)->generate($company, $owner, '2026-10-01', '2026-10-19'));

    expect($p['uninvoiced_holds_minor'])->toBe(4_500)
        ->and($p['debt']['closing_minor'])->toBe(0);
});

it('refuses a range over 12 months, in the future or backwards', function (string $from, string $to, string $field) {
    [, $owner] = stmCompany();

    $this->actingAs($owner)->postJson('/api/v1/trade/statements', ['from_on' => $from, 'to_on' => $to])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', $field);
    expect(AccountStatement::query()->count())->toBe(0);
})->with([
    'over 12 months' => ['2025-10-01', '2026-10-19', 'to_on'],
    'future' => ['2026-10-01', '2026-10-21', 'to_on'],
    'backwards' => ['2026-10-10', '2026-10-01', 'to_on'],
]);

it('generates through the API for owners only, and opens on its own page', function () {
    [$company, $owner] = stmCompany();
    $buyer = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $buyer->id, 'role' => 'buyer']);

    $this->actingAs($buyer)->postJson('/api/v1/trade/statements', ['from_on' => '2026-10-01', 'to_on' => '2026-10-19'])->assertForbidden();

    $id = $this->actingAs($owner)->postJson('/api/v1/trade/statements', ['from_on' => '2026-10-01', 'to_on' => '2026-10-19'])
        ->assertCreated()->json('data.id');
    $this->get("/trade/statements/{$id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Trade/Statements/Show', false)
        ->where('statement.from_on', '2026-10-01')
        ->where('statement.figures.debt.closing_minor', 0));
});
