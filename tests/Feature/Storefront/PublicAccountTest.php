<?php

use App\Domain\Ordering\GuestOrderClaims;
use App\Domain\Storefront\PublicAccountHistory;
use App\Models\Address;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->customer = User::factory()->create();
    $this->addressFields = ['label' => 'Home', 'contact_name' => 'Sam Lee', 'phone' => '07700 900123', 'line1' => '1 High Street', 'line2' => '', 'city' => 'London', 'county' => '', 'postcode' => ' e1 6an ', 'country_code' => 'gb'];
});

it('lists only placed public orders owned by the verified customer and reuses the existing order page', function () {
    $own = Order::factory()->create(['company_id' => null, 'user_id' => $this->customer->id, 'placed_at' => '2026-10-04 12:00:00+00', 'status' => 'confirmed']);
    Order::factory()->create(['company_id' => null, 'user_id' => $this->customer->id, 'placed_at' => null]);
    $other = Order::factory()->create(['company_id' => null]);
    Order::factory()->create(['user_id' => $this->customer->id]); // trade order, same user
    $this->actingAs($this->customer)->get('/account/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Account/Orders')->has('history.items', 1)->where('history.items.0.id', $own->public_id)->where('history.next', null));
    $this->get(route('orders.confirmation', $own->public_id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Orders/Confirmation')->where('order.id', $own->public_id)
        ->where('cancel_url', route('orders.cancel', $own->public_id))
        ->where('problems_url', route('orders.problems', $own->public_id)));
    $this->get(route('orders.confirmation', $other->public_id))->assertNotFound();
});

it('paginates timestamp ties without skipping or duplicating orders when a new order arrives', function () {
    $orders = Order::factory()->count(25)->create(['company_id' => null, 'user_id' => $this->customer->id, 'placed_at' => '2026-10-04 12:00:00.123456+00']);
    DB::table('orders')->whereIn('id', $orders->pluck('id'))->update(['placed_at' => '2026-10-04 12:00:00.123456+00']);
    $first = $this->actingAs($this->customer)->get('/account/orders')->assertOk()->viewData('page')['props']['history'];
    expect(array_column($first['items'], 'id'))->toBe($orders->reverse()->take(20)->pluck('public_id')->values()->all());
    $new = Order::factory()->create(['company_id' => null, 'user_id' => $this->customer->id, 'placed_at' => '2026-10-04 12:00:00.123457+00']);
    DB::table('orders')->where('id', $new->id)->update(['placed_at' => '2026-10-04 12:00:00.123457+00']);
    $second = $this->get('/account/orders?cursor='.urlencode($first['next']))->assertOk()->viewData('page')['props']['history'];
    expect(array_column($second['items'], 'id'))->toBe($orders->reverse()->skip(20)->pluck('public_id')->values()->all())
        ->and($second['next'])->toBeNull()
        ->and(array_column($second['items'], 'id'))->not->toContain($new->public_id);
    $this->get('/account/orders?cursor=broken')->assertSessionHasErrors('cursor');
    $this->get('/account/receipts?cursor='.urlencode($first['next']))->assertSessionHasErrors('cursor');
    $this->actingAs(User::factory()->create())->get('/account/orders?cursor='.urlencode($first['next']))->assertSessionHasErrors('cursor');
});

it('includes verified guest claims and never claims an unverified account history', function () {
    $guest = Order::factory()->create(['company_id' => null, 'user_id' => null, 'guest_email' => $this->customer->email]);
    $unverified = User::factory()->unverified()->create();
    expect((new GuestOrderClaims)->claimFor($unverified))->toBe([]);
    $this->actingAs($unverified)->get('/account/orders')->assertRedirect(route('verification.notice'));
    (new GuestOrderClaims)->claimFor($this->customer);
    $this->actingAs($this->customer)->get('/account/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->has('history.items', 1)->where('history.items.0.id', $guest->public_id));
});

it('denies guests, staff and trade customers access to the public shopping account', function () {
    $this->get('/account/orders')->assertRedirect(route('login'));
    $staff = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => (Role::query()->where('code', 'accounts')->first() ?? Role::factory()->create(['code' => 'accounts']))->id, 'user_id' => $staff->id]);
    $trade = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => $trade->id]);
    foreach ([$staff, $trade] as $user) {
        foreach (['orders', 'receipts', 'addresses'] as $section) {
            $this->actingAs($user)->get('/account/'.$section)->assertForbidden();
        }
    }
});

it('lists multiple owned receipts, hides voids, and securely serves archived PDFs', function () {
    Storage::fake((string) config('filesystems.default'));
    $order = Order::factory()->create(['company_id' => null, 'user_id' => $this->customer->id]);
    $receipt = Invoice::factory()->receipt()->paid()->create(['order_id' => $order->id]);
    Invoice::factory()->receipt()->paid()->create(['order_id' => $order->id]);
    $void = Invoice::factory()->receipt()->create(['order_id' => $order->id, 'status' => 'void']);
    $foreignOrder = Order::factory()->create(['company_id' => null]);
    $foreign = Invoice::factory()->receipt()->paid()->create(['order_id' => $foreignOrder->id]);
    $this->actingAs($this->customer)->get('/account/receipts')->assertOk()->assertInertia(fn (Assert $page) => $page->has('history.items', 2)->where('history.items.0.download_url', null));
    $this->get(route('account.receipts.download', $receipt->public_id))->assertNotFound();
    $disk = (string) config('filesystems.default');
    Storage::disk($disk)->put('invoices/owned.pdf', '%PDF-test');
    Attachment::query()->create(['attachable_type' => 'invoice', 'attachable_id' => $receipt->id, 'disk' => $disk, 'path' => 'invoices/owned.pdf', 'original_name' => 'receipt.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 9, 'is_customer_visible' => true]);
    $this->get(route('account.receipts.download', $receipt->public_id))->assertOk()->assertDownload($receipt->invoice_number.'.pdf');
    $this->get(route('account.receipts.download', $foreign->public_id))->assertNotFound();
    $this->get(route('account.receipts.download', $void->public_id))->assertNotFound();
    $items = $this->get('/account/receipts')->assertOk()->viewData('page')['props']['history']['items'];
    expect(collect($items)->firstWhere('id', $receipt->public_id)['download_url'])->toBe(route('account.receipts.download', $receipt->public_id));
});

it('adds, edits, switches defaults and promotes the oldest address on deletion', function () {
    $this->actingAs($this->customer)->post('/account/addresses', $this->addressFields)->assertSessionHasNoErrors()->assertRedirect('/account/addresses');
    $first = Address::query()->where('user_id', $this->customer->id)->sole();
    expect($first->is_default)->toBeTrue()->and($first->postcode)->toBe('E1 6AN')->and($first->company_id)->toBeNull();
    $this->post('/account/addresses', [...$this->addressFields, 'label' => 'Work', 'is_default' => true])->assertSessionHasNoErrors();
    $second = Address::query()->where('user_id', $this->customer->id)->where('id', '<>', $first->id)->sole();
    expect($first->fresh()->is_default)->toBeFalse()->and($second->is_default)->toBeTrue();
    $this->patch('/account/addresses/'.$first->public_id, [...$this->addressFields, 'line1' => '2 High Street'])->assertSessionHasNoErrors();
    expect($first->fresh()->line1)->toBe('2 High Street');
    $this->post('/account/addresses/'.$first->public_id.'/default')->assertRedirect();
    expect($first->fresh()->is_default)->toBeTrue()->and($second->fresh()->is_default)->toBeFalse();
    $this->delete('/account/addresses/'.$first->public_id)->assertRedirect();
    expect($second->fresh()->is_default)->toBeTrue();
    $this->delete('/account/addresses/'.$second->public_id)->assertRedirect();
    expect(Address::query()->where('user_id', $this->customer->id)->count())->toBe(0);
    $this->patch('/account/addresses/'.$first->public_id, $this->addressFields)->assertNotFound();
});

it('rejects foreign and company address mutations and invalid public address fields', function () {
    $foreign = Address::factory()->create();
    $this->actingAs($this->customer)->patch('/account/addresses/'.$foreign->public_id, $this->addressFields)->assertNotFound();
    $this->delete('/account/addresses/'.$foreign->public_id)->assertNotFound();
    $this->post('/account/addresses/'.$foreign->public_id.'/default')->assertNotFound();
    $this->post('/account/addresses', [...$this->addressFields, 'country_code' => 'FR'])->assertSessionHasErrors('country_code');
    $this->post('/account/addresses', [...$this->addressFields, 'contact_name' => '  '])->assertSessionHasErrors('contact_name');
});

it('enforces exclusive ownership and one live public default in database savepoints', function () {
    $this->actingAs($this->customer)->post('/account/addresses', $this->addressFields)->assertSessionHasNoErrors();
    $address = Address::query()->where('user_id', $this->customer->id)->sole();
    $company = Company::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('addresses')->where('id', $address->id)->update(['company_id' => $company->id])))
        ->toThrow(QueryException::class, 'addresses_owner_chk');
    expect(fn () => DB::transaction(fn () => DB::table('addresses')->where('id', $address->id)->update(['user_id' => null])))
        ->toThrow(QueryException::class, 'addresses_owner_chk');
    expect(fn () => DB::transaction(fn () => DB::table('addresses')->where('id', $address->id)->update(['country_code' => 'FR'])))
        ->toThrow(QueryException::class, 'addresses_public_delivery_chk');
    expect(fn () => DB::transaction(fn () => DB::table('addresses')->where('id', $address->id)->update(['address_type' => 'both'])))
        ->toThrow(QueryException::class, 'addresses_public_delivery_chk');
    $this->post('/account/addresses', [...$this->addressFields, 'label' => 'Second'])->assertSessionHasNoErrors();
    $second = Address::query()->where('user_id', $this->customer->id)->where('id', '<>', $address->id)->sole();
    expect(fn () => DB::transaction(fn () => DB::table('addresses')->where('id', $second->id)->update(['is_default' => true])))
        ->toThrow(QueryException::class, 'addresses_user_default_uq');
});

it('backfills ULIDs on legacy live and deleted company addresses before enforcing NOT NULL', function () {
    $migration = require database_path('migrations/2026_10_19_090100_add_public_account_addresses_and_history.php');
    $migration->down();
    $company = Company::factory()->create();
    $first = DB::table('addresses')->insertGetId(['company_id' => $company->id, 'line1' => '1 Old Road', 'city' => 'London', 'postcode' => 'E1 6AN']);
    $second = DB::table('addresses')->insertGetId(['company_id' => $company->id, 'line1' => '2 Old Road', 'city' => 'London', 'postcode' => 'E1 6AN', 'deleted_at' => now()]);
    $migration->up();
    $ids = DB::table('addresses')->whereIn('id', [$first, $second])->pluck('public_id')->all();
    expect($ids)->toHaveCount(2)->and($ids[0])->not->toBe($ids[1]);
    foreach ($ids as $id) {
        expect(Str::isUlid($id))->toBeTrue();
    }
    expect(fn () => DB::transaction(fn () => DB::table('addresses')->where('id', $first)->update(['public_id' => null])))
        ->toThrow(QueryException::class);
});

it('uses the public order history index for both first and cursor pages without a sort', function () {
    // Representative cardinality: many owners, a small indexed page for each.
    $users = User::factory()->count(30)->create();
    $template = Order::factory()->create(['company_id' => null, 'user_id' => $users[0]->id])->getRawOriginal();
    $rows = [];
    foreach ($users as $user) {
        for ($i = 0; $i < 200; $i++) {
            $rows[] = [...$template, 'id' => null, 'public_id' => (string) Str::ulid(), 'order_number' => 'EXPLAIN-'.Str::ulid(), 'user_id' => $user->id, 'placed_at' => '2026-10-04 12:00:00+00'];
        }
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        foreach ($chunk as &$row) {
            unset($row['id']);
        }
        unset($row);
        DB::table('orders')->insert($chunk);
    }
    DB::statement('ANALYZE orders');
    $query = (new PublicAccountHistory)->ordersQuery($users[0]);
    foreach ([false, true] as $after) {
        $page = clone $query;
        if ($after) {
            $page->whereRaw('(placed_at, id) < (?, ?)', ['2026-10-04 12:00:00+00', PHP_INT_MAX]);
        }
        $page->orderByDesc('placed_at')->orderByDesc('id')->limit(21)->select(['id', 'public_id', 'order_number', 'placed_at', 'status', 'payment_status', 'total_gross_minor']);
        $raw = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$page->toSql(), $page->getBindings());
        $plan = json_decode($raw[0]->{'QUERY PLAN'}, true);
        $text = json_encode($plan);
        expect($text)->toContain('orders_public_user_placed_idx')->not->toContain('"Node Type":"Sort"')->not->toContain('"Node Type":"Seq Scan"');
    }
});

it('includes every placed order status and scopes user-owned addresses as well as company ones', function () {
    $statuses = ['confirmed', 'picking', 'part_dispatched', 'dispatched', 'completed', 'cancelled'];
    foreach ($statuses as $status) {
        Order::factory()->create(['company_id' => null, 'user_id' => $this->customer->id, 'status' => $status]);
    }
    $this->actingAs($this->customer)->get('/account/orders')->assertOk()->assertInertia(fn (Assert $page) => $page->has('history.items', count($statuses)));
    $other = User::factory()->create();
    $foreign = Address::factory()->delivery()->create(['company_id' => null, 'user_id' => $other->id, 'contact_name' => 'Another customer', 'country_code' => 'GB']);
    $this->get('/account/addresses')->assertOk()->assertInertia(fn (Assert $page) => $page->has('addresses', 0));
    $this->patch('/account/addresses/'.$foreign->public_id, $this->addressFields)->assertNotFound();
    $this->delete('/account/addresses/'.$foreign->public_id)->assertNotFound();
    $this->post('/account/addresses/'.$foreign->public_id.'/default')->assertNotFound();
});

it('paginates owned receipts deterministically including several receipts for the same order', function () {
    $order = Order::factory()->create(['company_id' => null, 'user_id' => $this->customer->id]);
    $receipts = Invoice::factory()->receipt()->paid()->count(25)->create(['order_id' => $order->id, 'issued_at' => '2026-10-04 12:00:00+00']);
    $first = $this->actingAs($this->customer)->get('/account/receipts')->assertOk()->viewData('page')['props']['history'];
    $second = $this->get('/account/receipts?cursor='.urlencode($first['next']))->assertOk()->viewData('page')['props']['history'];
    expect(array_column($first['items'], 'id'))->toBe($receipts->reverse()->take(20)->pluck('public_id')->values()->all())
        ->and(array_column($second['items'], 'id'))->toBe($receipts->reverse()->skip(20)->pluck('public_id')->values()->all())
        ->and($second['next'])->toBeNull();
});
