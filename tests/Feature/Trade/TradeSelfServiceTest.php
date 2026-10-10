<?php

use App\Domain\Documents\ArchivedDocument;
use App\Domain\Documents\DocumentRenders;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\CreditNote;
use App\Models\DocumentRender;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\MinimalPdf;

/**
 * 05.17 §2, §4, §6 — trade self-service screens and their /api/v1 reads:
 * every company, role, public, applicant and staff cross-access including
 * guessed ids; finance redaction; policy re-checked on render polling and
 * download after membership revocation and company switch; keyset ties,
 * cursor binding and filter reset; drafts kept out of history; UK date
 * boundaries; constant queries per page.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake(config('documents.disk'));
});

function tssMember(Company $company, string $role): User
{
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function tssStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]))->id, 'user_id' => $user->id]);

    return $user;
}

function tssOrder(Company $company, array $overrides = []): Order
{
    return Order::factory()->create($overrides + [
        'company_id' => $company->id,
        'order_number' => 'SO-'.Str::upper(Str::random(8)),
        'status' => 'confirmed',
        'placed_at' => now(),
        'total_gross_minor' => 12_000,
    ]);
}

function tssInvoice(Company $company, array $overrides = []): Invoice
{
    return Invoice::factory()->create($overrides + [
        'company_id' => $company->id,
        'order_id' => tssOrder($company)->id,
        'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
        'status' => 'issued',
        'issued_at' => now(),
        'total_gross_minor' => 12_000,
    ]);
}

/** A captured, ready invoice render, as InvoiceService would leave it once printed. */
function tssReadyRender(Invoice $invoice): DocumentRender
{
    $renders = new DocumentRenders(new MinimalPdf);
    $render = $renders->capture('invoice', $invoice->id, $invoice->company_id, new ArchivedDocument('invoice', ['number' => $invoice->invoice_number, 'lines' => []]));
    $renders->render($render->id);

    return $render->fresh();
}

it('lets every member see the dashboard and orders, and only owners and approvers see money', function () {
    $company = Company::factory()->create(['credit_limit_minor' => 100_000, 'payment_terms' => 'net30']);
    tssOrder($company);

    foreach (['owner', 'approver'] as $role) {
        $this->actingAs(tssMember($company, $role))->get('/trade')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Trade/Dashboard', false)
            ->has('dashboard.finance.available_minor')
            ->where('auth.trade_navigation.finance', true));
    }
    foreach (['buyer', 'viewer'] as $role) {
        $user = tssMember($company, $role);
        $this->actingAs($user)->get('/trade')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dashboard.finance', null)
            ->where('auth.trade_navigation.finance', false)
            ->where('auth.trade_navigation.orders', true));
        $this->actingAs($user)->get('/trade/orders')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Trade/Orders/Index', false)->has('rows', 1));
        foreach (['/trade/invoices', '/trade/credit-notes', '/trade/statements'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
        $this->actingAs($user)->getJson('/api/v1/trade/invoices')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }
});

it('refuses trade pages to public customers, applicants and staff', function () {
    $company = Company::factory()->create();
    $invoice = tssInvoice($company);
    $applicant = User::factory()->create();
    B2bApplication::factory()->create(['applicant_user_id' => $applicant->id]);

    foreach ([User::factory()->create(), $applicant, tssStaff('accounts'), tssStaff('admin')] as $outsider) {
        foreach (['/trade', '/trade/orders', '/trade/invoices', "/trade/invoices/{$invoice->public_id}", "/trade/invoices/{$invoice->public_id}/download"] as $path) {
            $this->actingAs($outsider)->get($path)->assertForbidden();
        }
    }
    $this->post('/logout');
    $this->get('/trade/invoices')->assertRedirect(route('login'));
});

it('answers another company\'s ids, guessed or real, with not found', function () {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();
    $owner = tssMember($mine, 'owner');
    $order = tssOrder($theirs);
    $invoice = tssInvoice($theirs);
    $note = CreditNote::query()->create(['credit_note_number' => 'CN-X1', 'company_id' => $theirs->id, 'reason' => 'goodwill', 'total_gross_minor' => 100, 'issued_at' => now()]);
    $render = tssReadyRender($invoice);

    $this->actingAs($owner);
    foreach ([
        "/trade/orders/{$order->public_id}", "/trade/invoices/{$invoice->public_id}", "/trade/invoices/{$invoice->public_id}/download",
        "/trade/credit-notes/{$note->public_id}", '/trade/invoices/'.Str::ulid(), '/trade/statements/'.Str::ulid(),
    ] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->getJson("/api/v1/trade/orders/{$order->public_id}")->assertNotFound();
    $this->getJson("/api/v1/document-renders/{$render->public_id}")->assertNotFound();
    $this->postJson('/api/v1/document-renders', ['type' => 'invoice', 'source' => $invoice->public_id])->assertNotFound();
});

it('re-checks the source policy on polling and download after a membership change', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    $invoice = tssInvoice($company);
    $render = tssReadyRender($invoice);

    $this->actingAs($owner)->getJson("/api/v1/document-renders/{$render->public_id}")->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.download_url', route('trade.invoices.download', $invoice->public_id));
    $download = $this->actingAs($owner)->get("/trade/invoices/{$invoice->public_id}/download")->assertOk();
    expect($download->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store')
        ->and($download->headers->get('Cache-Control'))->toContain('private');

    // Demoted to buyer: the same session can no longer poll or download.
    DB::table('company_users')->where('company_id', $company->id)->where('user_id', $owner->id)->update(['role' => 'buyer']);
    $this->actingAs($owner)->getJson("/api/v1/document-renders/{$render->public_id}")->assertForbidden();
    $this->actingAs($owner)->get("/trade/invoices/{$invoice->public_id}/download")->assertForbidden();

    // Removed from the company altogether.
    DB::table('company_users')->where('company_id', $company->id)->where('user_id', $owner->id)->delete();
    $this->actingAs($owner)->get("/trade/invoices/{$invoice->public_id}/download")->assertForbidden();
});

it('scopes renders to the company being acted for after a company switch', function () {
    $first = Company::factory()->create();
    $second = Company::factory()->create();
    $user = tssMember($first, 'owner');
    CompanyUser::factory()->create(['company_id' => $second->id, 'user_id' => $user->id, 'role' => 'owner']);
    $render = tssReadyRender($invoice = tssInvoice($first));

    $this->actingAs($user)->post('/choose-company', ['company' => $first->public_id]);
    $this->getJson("/api/v1/document-renders/{$render->public_id}")->assertOk();

    $this->post('/choose-company', ['company' => $second->public_id]);
    $this->getJson("/api/v1/document-renders/{$render->public_id}")->assertNotFound();
    $this->get("/trade/invoices/{$invoice->public_id}/download")->assertNotFound();
});

it('answers a download that is not ready with 409 and the page to prepare it from, never queuing', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    $invoice = tssInvoice($company);

    $this->actingAs($owner)->get("/trade/invoices/{$invoice->public_id}/download")
        ->assertStatus(409)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(DocumentRender::query()->count())->toBe(0);

    // No captured payload: the explicit prepare is refused, not rebuilt.
    $this->postJson('/api/v1/document-renders', ['type' => 'invoice', 'source' => $invoice->public_id])
        ->assertStatus(409)->assertJsonPath('error.code', 'missing_snapshot');
});

it('pages order history by keyset with stable ties, binds the cursor to its filters, and keeps drafts out', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    $at = CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC');
    $ids = [];
    foreach (range(1, 51) as $i) {
        // Every order at the same instant: the id alone orders them.
        $ids[] = tssOrder($company, ['placed_at' => $at])->public_id;
    }
    tssOrder($company, ['status' => 'draft', 'placed_at' => null, 'order_number' => 'SO-DRAFT']);

    $first = $this->actingAs($owner)->get('/trade/orders')->assertOk();
    $props = $first->viewData('page')['props'];
    expect($props['rows'])->toHaveCount(50)
        ->and(array_column($props['drafts'], 'order_number'))->toBe(['SO-DRAFT'])
        ->and(in_array('SO-DRAFT', array_column($props['rows'], 'order_number'), true))->toBeFalse();

    $second = $this->get('/trade/orders?cursor='.urlencode($props['next_cursor']))->viewData('page')['props'];
    $seen = [...array_column($props['rows'], 'id'), ...array_column($second['rows'], 'id')];
    expect($second['rows'])->toHaveCount(1)
        ->and(count(array_unique($seen)))->toBe(51)
        ->and(array_diff($ids, $seen))->toBe([]);

    $this->get('/trade/orders?status=cancelled&cursor='.urlencode($props['next_cursor']))->assertSessionHasErrors('cursor');
    $this->get('/trade/orders?sort=placed_asc&cursor='.urlencode($props['next_cursor']))->assertSessionHasErrors('cursor');
});

it('serves the API list as 06 DTOs, 100 at most', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    foreach (range(1, 3) as $i) {
        tssOrder($company, ['placed_at' => now()->subMinutes($i)]);
    }

    $this->actingAs($owner)->getJson('/api/v1/trade/orders?per_page=2')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('has_more', true)->assertJsonStructure(['data', 'next_cursor', 'has_more']);
    $this->getJson('/api/v1/trade/orders?per_page=101')->assertStatus(422);
});

it('filters orders by UK calendar day, across the BST boundary', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    // 23:30 UTC on 30 September is 00:30 on 1 October in London (BST).
    $october = tssOrder($company, ['placed_at' => CarbonImmutable::parse('2026-09-30 23:30:00', 'UTC')]);
    $september = tssOrder($company, ['placed_at' => CarbonImmutable::parse('2026-09-30 22:30:00', 'UTC')]);

    $rows = $this->actingAs($owner)->get('/trade/orders?from=2026-10-01&to=2026-10-01')->viewData('page')['props']['rows'];
    expect(array_column($rows, 'id'))->toBe([$october->public_id]);
    $rows = $this->get('/trade/orders?from=2026-09-30&to=2026-09-30')->viewData('page')['props']['rows'];
    expect(array_column($rows, 'id'))->toBe([$september->public_id]);
});

it('shows order detail to buyers without invoices, and to owners with them, never costs', function () {
    $company = Company::factory()->create();
    $order = tssOrder($company);
    tssInvoice($company, ['order_id' => $order->id]);

    $this->actingAs(tssMember($company, 'viewer'))->get("/trade/orders/{$order->public_id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Orders/Show', false)->where('order.invoices', null)->where('order.credit_notes', null));
    $response = $this->actingAs(tssMember($company, 'owner'))->get("/trade/orders/{$order->public_id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('order.invoices', 1));
    expect(json_encode($response->viewData('page')['props']['order']))->not->toContain('cost');
});

it('lists invoices with outstanding as total − paid − credited, and filters overdue', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    $overdue = tssInvoice($company, ['total_gross_minor' => 10_000, 'paid_minor' => 2_000, 'credited_minor' => 1_000, 'due_at' => now()->subDays(3)]);
    tssInvoice($company, ['status' => 'paid', 'paid_minor' => 12_000, 'due_at' => now()->subDays(3)]);

    $this->actingAs($owner)->get('/trade/invoices?status=overdue')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Trade/Invoices/Index', false)
        ->has('rows', 1)
        ->where('rows.0.id', $overdue->public_id)
        ->where('rows.0.outstanding_minor', 7_000)
        ->where('rows.0.overdue', true)
        ->where('summary.overdue_minor', 7_000));
});

it('keeps queries constant as the order history grows', function () {
    $company = Company::factory()->create();
    $owner = tssMember($company, 'owner');
    $count = function () use ($owner): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get('/trade/orders')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    tssOrder($company);
    $count(); // warm-up: one-off per-session lookups land here, not in the comparison
    $few = $count();
    foreach (range(1, 30) as $i) {
        tssOrder($company);
    }

    expect($count())->toBe($few);
});
