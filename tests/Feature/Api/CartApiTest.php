<?php

use App\Domain\Ordering\CartOwner;
use App\Domain\Ordering\CartService;
use App\Http\Support\CartContext;
use App\Models\Cart;
use App\Models\CartLine;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Pack;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{sku: Sku, each: Pack, outer: Pack}
 */
function cartApiSku(array $skuAttributes = []): array
{
    $sku = Sku::factory()->create($skuAttributes);
    $each = Pack::factory()->for($sku)->create(['base_units' => 1]);
    $outer = Pack::factory()->for($sku)->outer(12)->create();

    return ['sku' => $sku, 'each' => $each, 'outer' => $outer];
}

function cartApiCompanyUser(?Company $company = null): User
{
    $user = User::factory()->create();
    CompanyUser::factory()->create(['company_id' => ($company ?? Company::factory()->create())->id, 'user_id' => $user->id]);

    return $user;
}

it('returns an empty cart without creating one when the caller has none', function () {
    $this->getJson('/api/v1/cart')
        ->assertOk()
        ->assertExactJson(['data' => ['id' => null, 'line_count' => 0, 'lines' => []]]);

    expect(Cart::query()->count())->toBe(0);
});

it('adds a line by SKU ULID and pack code, answering 201 with ULIDs only and no price fields', function () {
    ['sku' => $sku] = cartApiSku();
    $user = cartApiCompanyUser();

    $response = $this->actingAs($user)->postJson('/api/v1/cart/lines', [
        'sku_id' => $sku->public_id,
        'pack_code' => 'OUTER12',
        'pack_qty' => 3,
        'base_qty' => 36,
    ]);

    $line = CartLine::query()->sole();

    $response->assertCreated()
        ->assertHeader('Location', url("/api/v1/cart/lines/{$line->public_id}"))
        ->assertJsonPath('data.id', Cart::query()->sole()->public_id)
        ->assertJsonPath('data.lines.0.id', $line->public_id)
        ->assertJsonPath('data.lines.0.sku.id', $sku->public_id)
        ->assertJsonPath('data.lines.0.pack', ['code' => 'OUTER12', 'label' => 'Outer of 12', 'base_units' => 12])
        ->assertJsonPath('data.lines.0.pack_qty', 3)
        ->assertJsonPath('data.lines.0.base_qty', 36);

    $body = $response->getContent();
    expect($body)->not->toContain('"cart_id"')
        ->and($body)->not->toContain('"sku_id":'.$sku->id)
        ->and($body)->not->toContain('price')
        ->and($body)->not->toContain('cost');
});

it('uses the default sell pack when pack_code is omitted', function () {
    ['sku' => $sku, 'each' => $each] = cartApiSku();

    $this->actingAs(cartApiCompanyUser())
        ->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 2])
        ->assertCreated()
        ->assertJsonPath('data.lines.0.pack.code', $each->code);
});

it('answers 200 and sums onto the existing line when the same sku+pack is added again', function () {
    ['sku' => $sku] = cartApiSku();
    $user = cartApiCompanyUser();

    $this->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 2])->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 5])
        ->assertOk()
        ->assertJsonPath('data.line_count', 1)
        ->assertJsonPath('data.lines.0.pack_qty', 7);
});

it('rejects inactive SKUs, unsellable packs and a base_qty that disagrees with the pack, in the 06 §4 envelope', function (array $skuAttributes, array $payload, string $code) {
    ['sku' => $sku] = cartApiSku($skuAttributes);
    Pack::query()->where('sku_id', $sku->id)->where('code', 'OUTER12')->update(['is_sellable' => false]);

    $this->actingAs(cartApiCompanyUser())
        ->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id] + $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_cart_item')
        ->assertJsonPath('error.details.0.code', $code)
        ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

    expect(CartLine::query()->count())->toBe(0);
})->with([
    'discontinued sku' => [['status' => 'discontinued'], ['pack_qty' => 1], 'not_purchasable'],
    'unsellable pack' => [[], ['pack_code' => 'OUTER12', 'pack_qty' => 1], 'pack_not_sellable'],
    'unknown pack' => [[], ['pack_code' => 'PALLET', 'pack_qty' => 1], 'pack_not_found'],
    'base_qty mismatch' => [[], ['pack_code' => 'EACH', 'pack_qty' => 3, 'base_qty' => 4], 'base_qty_mismatch'],
]);

it('rejects a non-ULID sku_id as a validation failure in the envelope', function () {
    $this->postJson('/api/v1/cart/lines', ['sku_id' => '42', 'pack_qty' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.field', 'sku_id');
});

it('changes pack via PATCH as an in-place line update (05.1 §8.3)', function () {
    ['sku' => $sku] = cartApiSku();
    $user = cartApiCompanyUser();

    $this->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_code' => 'EACH', 'pack_qty' => 24]);
    $line = CartLine::query()->sole();

    $this->actingAs($user)->patchJson("/api/v1/cart/lines/{$line->public_id}", ['pack_code' => 'OUTER12', 'pack_qty' => 2])
        ->assertOk()
        ->assertJsonPath('data.lines.0.id', $line->public_id)
        ->assertJsonPath('data.lines.0.pack.code', 'OUTER12')
        ->assertJsonPath('data.lines.0.base_qty', 24);
});

it('updates quantity via PATCH and deletes via DELETE', function () {
    ['sku' => $sku] = cartApiSku();
    $user = cartApiCompanyUser();

    $this->actingAs($user)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 1]);
    $line = CartLine::query()->sole();

    $this->actingAs($user)->patchJson("/api/v1/cart/lines/{$line->public_id}", ['pack_qty' => 9])
        ->assertOk()
        ->assertJsonPath('data.lines.0.pack_qty', 9);

    $this->actingAs($user)->deleteJson("/api/v1/cart/lines/{$line->public_id}")->assertNoContent();

    expect(CartLine::query()->count())->toBe(0);
});

it('shares one cart between users of the same company (05.1 §10)', function () {
    ['sku' => $sku] = cartApiSku();
    $company = Company::factory()->create();
    $alice = cartApiCompanyUser($company);
    $bob = cartApiCompanyUser($company);

    $this->actingAs($alice)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 1]);

    $this->actingAs($bob)->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.line_count', 1);

    expect(Cart::query()->count())->toBe(1);
});

it('never lets one company read or touch another company\'s cart lines — 404, not 403', function () {
    ['sku' => $sku] = cartApiSku();
    $mine = cartApiCompanyUser();
    $theirs = cartApiCompanyUser();

    $this->actingAs($theirs)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 4]);
    $theirLine = CartLine::query()->sole();

    // Give "mine" a cart too, so the 404 is not just "you have no cart".
    $this->actingAs($mine)->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_code' => 'OUTER12', 'pack_qty' => 1]);

    $this->actingAs($mine)->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.line_count', 1)
        ->assertJsonMissing(['id' => $theirLine->public_id]);

    $this->actingAs($mine)->patchJson("/api/v1/cart/lines/{$theirLine->public_id}", ['pack_qty' => 99])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->actingAs($mine)->deleteJson("/api/v1/cart/lines/{$theirLine->public_id}")->assertNotFound();

    expect($theirLine->fresh()->pack_qty)->toBe(4);
});

it('keeps guest carts apart by session token and never exposes an owned cart to a guest', function () {
    ['sku' => $sku] = cartApiSku();

    $this->withSession([CartContext::SESSION_KEY => str_repeat('a', 64)])
        ->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 2])
        ->assertCreated();
    $guestLine = CartLine::query()->sole();

    $this->withSession([CartContext::SESSION_KEY => str_repeat('b', 64)])
        ->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.id', null);

    $this->withSession([CartContext::SESSION_KEY => str_repeat('b', 64)])
        ->deleteJson("/api/v1/cart/lines/{$guestLine->public_id}")
        ->assertNotFound();

    // A company cart's own session_token grants a guest nothing.
    $companyCart = (new CartService)->cartFor(CartOwner::company(Company::factory()->create()->id, User::factory()->create()->id));
    $this->withSession([CartContext::SESSION_KEY => $companyCart->session_token])
        ->getJson('/api/v1/cart')
        ->assertJsonPath('data.id', null);
});

it('bulk-add is all-or-nothing: one rejected line and nothing is added', function () {
    ['sku' => $a] = cartApiSku();
    ['sku' => $inactive] = cartApiSku(['status' => 'discontinued']);

    $this->actingAs(cartApiCompanyUser())->postJson('/api/v1/cart/bulk-add', ['lines' => [
        ['sku_id' => $a->public_id, 'pack_qty' => 1],
        ['sku_id' => $inactive->public_id, 'pack_qty' => 1],            // line 2: discontinued
        ['sku_id' => '01J8ZZZZZZZZZZZZZZZZZZZZZZ', 'pack_qty' => 1],     // line 3: valid ULID, no such SKU
    ]])
        ->assertUnprocessable()
        ->assertJsonCount(2, 'error.details')
        ->assertJsonPath('error.details.0.field', 'lines.1.sku_id')
        ->assertJsonPath('error.details.0.code', 'not_purchasable')
        ->assertJsonPath('error.details.1.field', 'lines.2.sku_id')
        ->assertJsonPath('error.details.1.code', 'sku_not_found');

    expect(CartLine::query()->count())->toBe(0);
});

it('bulk-add merges a SKU that appears twice in one payload instead of rejecting it (05.1 §7.1)', function () {
    ['sku' => $a] = cartApiSku();
    ['sku' => $b, 'outer' => $bOuter] = cartApiSku();

    $this->actingAs(cartApiCompanyUser())->postJson('/api/v1/cart/bulk-add', ['lines' => [
        ['sku_id' => $a->public_id, 'pack_qty' => 1],
        ['sku_id' => $b->public_id, 'pack_code' => $bOuter->code, 'pack_qty' => 2],
        ['sku_id' => $a->public_id, 'pack_qty' => 4],
    ]])
        ->assertOk()
        ->assertJsonPath('data.line_count', 2);

    expect(CartLine::query()->where('sku_id', $a->id)->sole()->pack_qty)->toBe(5)
        ->and(CartLine::query()->where('sku_id', $b->id)->sole()->pack_qty)->toBe(2);
});

it('tells a buyer pasting 60 lines exactly which lines failed and why (05.1 §7, 06 §4)', function () {
    ['sku' => $sku] = cartApiSku();
    ['sku' => $inactive] = cartApiSku(['status' => 'discontinued']);

    $lines = array_fill(0, 60, ['sku_id' => $sku->public_id, 'pack_qty' => 1]);
    $lines[36] = ['sku_id' => $inactive->public_id, 'pack_qty' => 1];                // line 37
    $lines[49] = ['sku_id' => $sku->public_id, 'pack_code' => 'PALLET', 'pack_qty' => 1]; // line 50

    $response = $this->actingAs(cartApiCompanyUser())
        ->postJson('/api/v1/cart/bulk-add', ['lines' => $lines])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_cart_item')
        ->assertJsonCount(2, 'error.details');

    expect($response->json('error.details.0'))->toMatchArray([
        'field' => 'lines.36.sku_id',
        'code' => 'not_purchasable',
    ])
        ->and($response->json('error.details.0.meta'))->toMatchArray(['line_index' => 36, 'sku_id' => $inactive->public_id, 'status' => 'discontinued'])
        ->and($response->json('error.details.1'))->toMatchArray([
            'field' => 'lines.49.pack_code',
            'code' => 'pack_not_found',
        ])
        ->and($response->json('error.details.1.meta'))->toMatchArray(['line_index' => 49, 'pack_code' => 'PALLET'])
        ->and($response->json('error.message'))->toContain('2 of 60')
        ->and($response->json('error.message'))->toContain('37, 50')
        ->and(CartLine::query()->count())->toBe(0);
});

/*
 * The race itself (two concurrent first-adds) needs two committed
 * connections and cannot run inside RefreshDatabase's wrapping
 * transaction. What can be asserted here is the mechanism that fixes
 * it: addLine() is one INSERT ... ON CONFLICT DO UPDATE, so the "row
 * already exists" outcome goes through the conflict arm rather than a
 * second INSERT — and cart creation is idempotent per owner.
 */
it('resolves a same-key add through ON CONFLICT rather than a duplicate-key error', function () {
    ['each' => $pack] = cartApiSku();
    $cart = Cart::factory()->create();
    $service = new CartService;

    $first = $service->upsertLine($cart, $pack, 2);
    $second = $service->upsertLine($cart, $pack, 3);

    expect($first['inserted'])->toBeTrue()
        ->and($second['inserted'])->toBeFalse()
        ->and($second['line']->id)->toBe($first['line']->id)
        ->and($second['line']->public_id)->toBe($first['line']->public_id)
        ->and($second['line']->pack_qty)->toBe(5)
        ->and($second['line']->base_qty)->toBe(5)
        ->and(CartLine::query()->count())->toBe(1);
});

it('returns the same cart for repeated cartFor() calls on one owner', function () {
    $owner = CartOwner::company(Company::factory()->create()->id, User::factory()->create()->id);
    $service = new CartService;

    expect($service->cartFor($owner)->id)->toBe($service->cartFor($owner)->id)
        ->and(Cart::query()->count())->toBe(1);

    $guest = CartOwner::guest(str_repeat('g', 64));
    expect($service->cartFor($guest)->id)->toBe($service->cartFor($guest)->id)
        ->and(Cart::query()->count())->toBe(2);
});

it('adopts the guest cart at login when the account has no cart yet', function () {
    ['each' => $pack] = cartApiSku();
    $service = new CartService;
    $guestCart = $service->cartFor(CartOwner::guest(str_repeat('t', 64)));
    $line = $service->addLine($guestCart, $pack, 3);
    $user = cartApiCompanyUser();
    $companyId = CompanyUser::query()->where('user_id', $user->id)->value('company_id');

    $merged = $service->mergeGuestCart(str_repeat('t', 64), CartOwner::company((int) $companyId, $user->id));

    expect($merged->id)->toBe($guestCart->id)
        ->and($merged->company_id)->toBe((int) $companyId)
        ->and($line->fresh()->cart_id)->toBe($guestCart->id);
});

it('merges guest lines onto the existing account cart at login, summing matching lines', function () {
    ['sku' => $sku, 'each' => $each, 'outer' => $outer] = cartApiSku();
    $service = new CartService;
    $user = cartApiCompanyUser();
    $owner = CartOwner::company((int) CompanyUser::query()->where('user_id', $user->id)->value('company_id'), $user->id);

    $accountCart = $service->cartFor($owner);
    $service->addLine($accountCart, $each, 2);

    $guestCart = $service->cartFor(CartOwner::guest(str_repeat('m', 64)));
    $service->addLine($guestCart, $each, 5);
    $service->addLine($guestCart, $outer, 1);

    $service->mergeGuestCart(str_repeat('m', 64), $owner);

    expect(Cart::query()->whereKey($guestCart->id)->exists())->toBeFalse()
        ->and($accountCart->lines()->where('pack_id', $each->id)->sole()->pack_qty)->toBe(7)
        ->and($accountCart->lines()->where('pack_id', $outer->id)->sole()->pack_qty)->toBe(1);
});

it('merges on the Login event and forgets the guest token', function () {
    ['sku' => $sku] = cartApiSku();
    $token = str_repeat('l', 64);

    $this->withSession([CartContext::SESSION_KEY => $token])
        ->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 2])
        ->assertCreated();

    $user = cartApiCompanyUser();
    $request = request();
    $request->setLaravelSession(app('session.store'));

    event(new Login('web', $user, false));

    expect(session()->has(CartContext::SESSION_KEY))->toBeFalse()
        ->and(Cart::query()->sole()->company_id)->not->toBeNull();

    $this->actingAs($user)->getJson('/api/v1/cart')->assertJsonPath('data.lines.0.pack_qty', 2);
});
