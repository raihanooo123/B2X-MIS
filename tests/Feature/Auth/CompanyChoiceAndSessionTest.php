<?php

use App\Domain\Identity\Exceptions\CompanyChoiceRequiredException;
use App\Domain\Ordering\CartOwner;
use App\Domain\Ordering\CartOwnerResolver;
use App\Http\Support\ActingCompany;
use App\Models\Cart;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Pack;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * 05.13 §6.3 (company choice), §8.4 (guest cart merge after the choice)
 * and §13 (session limits).
 */
const CHOICE_PASSWORD = 'a-long-enough-passphrase';

function choiceUser(int $companies, string $status = 'approved'): User
{
    $user = User::factory()->create(['password_hash' => Hash::make(CHOICE_PASSWORD)]);
    for ($i = 0; $i < $companies; $i++) {
        CompanyUser::factory()->create(['company_id' => Company::factory()->create(['status' => $status])->id, 'user_id' => $user->id]);
    }

    return $user;
}

function choiceGuestCart(): Pack
{
    $sku = Sku::factory()->create();
    $pack = Pack::factory()->for($sku)->create(['base_units' => 1]);
    test()->postJson('/api/v1/cart/lines', ['sku_id' => $sku->public_id, 'pack_qty' => 3])->assertCreated();

    return $pack;
}

it('skips the choice for a user in one company', function () {
    $user = choiceUser(1);

    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD])->assertRedirect(route('order-pad'));
    $this->get('/order-pad')->assertOk();
});

it('requires a choice from a user in several companies before anything prices or carts', function () {
    $user = choiceUser(2);

    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD])->assertRedirect(route('company.choose'));

    $this->get('/order-pad')->assertRedirect(route('company.choose'));
    $this->getJson('/api/v1/cart')->assertStatus(409)->assertJsonPath('error.code', 'company_choice_required');
    $this->get('/choose-company')->assertOk();
});

it('acts for the chosen company, and asks again at the next sign-in', function () {
    $user = choiceUser(2);
    $chosen = $user->companies()->orderByDesc('companies.id')->firstOrFail();

    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);
    $this->post('/choose-company', ['company' => $chosen->public_id])->assertRedirect(route('order-pad'));

    expect(session(ActingCompany::SESSION_KEY))->toBe($chosen->id);
    $this->get('/order-pad')->assertOk();

    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD])->assertRedirect(route('company.choose'));
});

it('refuses a company the user does not belong to', function () {
    $user = choiceUser(2);
    $stranger = Company::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);
    $this->post('/choose-company', ['company' => $stranger->public_id])->assertSessionHasErrors('company');

    expect(session(ActingCompany::SESSION_KEY))->toBeNull();
});

it('does not offer companies that are not approved or suspended', function () {
    $user = choiceUser(1);
    CompanyUser::factory()->create(['company_id' => Company::factory()->create(['status' => 'closed'])->id, 'user_id' => $user->id]);

    // One active company: no choice, even though two memberships exist.
    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD])->assertRedirect(route('order-pad'));
});

it('merges the guest cart into the chosen company, not at sign-in (§8.4)', function () {
    $user = choiceUser(2);
    $chosen = $user->companies()->orderByDesc('companies.id')->firstOrFail();
    choiceGuestCart();

    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);

    // Nothing merged yet: the guest cart is still a guest cart.
    expect(Cart::query()->sole()->company_id)->toBeNull();

    $this->post('/choose-company', ['company' => $chosen->public_id]);

    expect(Cart::query()->sole()->company_id)->toBe($chosen->id);
    $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.lines.0.pack_qty', 3);

    // Switching company later never merges again.
    $other = $user->companies()->where('companies.id', '!=', $chosen->id)->firstOrFail();
    $this->post('/choose-company', ['company' => $other->public_id]);
    expect(Cart::query()->where('company_id', $other->id)->exists())->toBeFalse();
});

it('merges the guest cart at sign-in for a user in one company', function () {
    $user = choiceUser(1);
    choiceGuestCart();

    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);

    expect(Cart::query()->sole()->company_id)->toBe($user->companies()->firstOrFail()->id);
});

it('resolves the cart owner from the choice, and refuses to guess without one', function () {
    $user = choiceUser(2);
    $ids = $user->companies()->orderBy('companies.id')->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
    $resolver = new CartOwnerResolver;

    expect($resolver->forUser($user, $ids[1]))->toEqual(CartOwner::company($ids[1], $user->id))
        ->and($resolver->forUser(choiceUser(0)))->toEqual(CartOwner::user(User::query()->latest('id')->firstOrFail()->id));

    $resolver->forUser($user);
})->throws(CompanyChoiceRequiredException::class);

it('expires a customer session after 12 hours idle', function () {
    $user = choiceUser(1);
    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);

    $this->travel(11)->hours();
    $this->get('/order-pad')->assertOk();
    $this->assertAuthenticated();

    $this->travel(12 * 60 + 1)->minutes();
    $this->get('/order-pad')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('expires staff after 4 hours idle and admin after 1, the strictest role winning', function (string $role, int $idleHours) {
    $user = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::factory()->create(['code' => $role])->id, 'user_id' => $user->id]);
    // A trade buyer too — the stricter staff limit still applies.
    CompanyUser::factory()->create(['company_id' => Company::factory()->create()->id, 'user_id' => $user->id]);

    $this->actingAs($user)->get('/order-pad')->assertOk();

    $this->travel($idleHours * 60 - 1)->minutes();
    $this->get('/order-pad')->assertOk();

    $this->travel($idleHours * 60 + 1)->minutes();
    $this->get('/order-pad')->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'staff' => ['warehouse', 4],
    'admin' => ['admin', 1],
]);

it('ends every session after 7 days, however active', function () {
    $user = choiceUser(1);
    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);

    // 15 × 11 h = 165 h, inside 7 days (168 h), never idle for 12 h.
    foreach (range(1, 15) as $_) {
        $this->travel(11)->hours();
        $this->get('/order-pad')->assertOk();
    }

    $this->travel(4)->hours();
    $this->get('/order-pad')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends a session on the next request once the user is suspended', function () {
    $user = choiceUser(1);
    $this->post('/login', ['email' => $user->email, 'password' => CHOICE_PASSWORD]);

    $user->forceFill(['status' => 'suspended'])->save();
    // A real request loads the user afresh; the test guard caches it.
    $this->app['auth']->forgetGuards();

    $this->get('/order-pad')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->getJson('/api/v1/cart')->assertOk(); // now simply a guest
});
