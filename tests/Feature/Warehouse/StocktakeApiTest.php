<?php

use App\Models\Location;
use App\Models\Pack;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Sku;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * 06 §8 `/warehouse/stocktakes*` and the stocktake page: StocktakePolicy,
 * blind counting (no system figure while counting), counts in packs, the
 * review figures of 02 §24.1, and reasons addressed by SKU and batch.
 */
beforeEach(function () {
    $this->withoutVite();
    Carbon::setTestNow('2026-09-26 09:00:00');
    $this->location = Location::factory()->default()->create(['code' => 'MAIN']);
    $this->sku = Sku::factory()->create(['sku_code' => 'TIN-1']);
    $this->outer = Pack::factory()->for($this->sku)->outer(12)->create();
    StockLevel::factory()->for($this->sku)->for($this->location)->create(['on_hand_base_qty' => 40, 'allocated_base_qty' => 0]);
});

afterEach(fn () => Carbon::setTestNow());

function stocktakeStaff(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $roleModel = Role::query()->where('code', $role)->first() ?? Role::factory()->create(['code' => $role]);
    RoleUser::create(['role_id' => $roleModel->id, 'user_id' => $user->id]);

    return $user;
}

function stocktakeStart(User $user, bool $blind): string
{
    return test()->actingAs($user)
        ->postJson('/api/v1/warehouse/stocktakes', ['location_code' => 'MAIN', 'blind' => $blind])
        ->assertCreated()
        ->json('data.id');
}

function stocktakeCount(User $user, string $id, int $packs, int $loose = 0)
{
    return test()->actingAs($user)->postJson("/api/v1/warehouse/stocktakes/{$id}/lines", [
        'sku_id' => test()->sku->public_id,
        'pack_code' => test()->outer->code,
        'pack_qty' => $packs,
        'loose_units' => $loose,
    ]);
}

it('limits counting to the warehouse', function () {
    $this->postJson('/api/v1/warehouse/stocktakes', ['location_code' => 'MAIN'])->assertStatus(401);

    foreach ([User::factory()->create(), stocktakeStaff('purchasing')] as $user) {
        $this->actingAs($user)->postJson('/api/v1/warehouse/stocktakes', ['location_code' => 'MAIN'])->assertForbidden();
    }
});

it('hides every system figure during a blind count', function () {
    $user = stocktakeStaff('warehouse');
    $id = stocktakeStart($user, true);

    $response = stocktakeCount($user, $id, 3, 2)
        ->assertOk()
        ->assertJsonPath('data.is_blind', true)
        ->assertJsonPath('data.lines.0.counted_base_qty', 38);

    expect($response->json('data.lines.0'))->not->toHaveKey('system_base_qty')->not->toHaveKey('review');
});

it('shows the system figure during an open count', function () {
    $user = stocktakeStaff('warehouse');
    $id = stocktakeStart($user, false);

    stocktakeCount($user, $id, 3)->assertOk()->assertJsonPath('data.lines.0.system_base_qty', 40);
});

it('reviews against stock as counted, then posts with a reason addressed by SKU', function () {
    $user = stocktakeStaff('warehouse');
    $id = stocktakeStart($user, true);
    stocktakeCount($user, $id, 3, 2)->assertOk(); // 38 counted of 40
    Carbon::setTestNow('2026-09-26 09:05:00');
    $movement = StockMovement::create(['occurred_at' => now(), 'sku_id' => $this->sku->id, 'location_id' => $this->location->id, 'movement_type' => 'dispatch', 'base_qty' => -10]);
    StockLevel::identity($this->sku->id, $this->location->id)->update(['on_hand_base_qty' => 30, 'last_movement_id' => $movement->id]);

    $this->actingAs($user)->postJson("/api/v1/warehouse/stocktakes/{$id}/review")
        ->assertOk()
        ->assertJsonPath('data.status', 'review')
        ->assertJsonPath('data.lines.0.review.on_hand_now', 30)
        ->assertJsonPath('data.lines.0.review.expected_at_count', 40)
        ->assertJsonPath('data.lines.0.review.variance_base_qty', -2);

    $this->actingAs($user)->postJson("/api/v1/warehouse/stocktakes/{$id}/post")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'stocktake_not_postable')
        ->assertJsonPath('error.details.0.code', 'variance_reason_required');

    $this->actingAs($user)->postJson("/api/v1/warehouse/stocktakes/{$id}/post", ['reasons' => [['sku_id' => $this->sku->public_id, 'reason' => 'damaged']]])
        ->assertOk()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.lines.0.posted.expected_base_qty', 40)
        ->assertJsonPath('data.lines.0.posted.variance_base_qty', -2)
        ->assertJsonPath('data.lines.0.posted.reason_code', 'damaged');

    expect(StockLevel::identity($this->sku->id, $this->location->id)->value('on_hand_base_qty'))->toBe(28);
});

it('refuses a count in a pack the SKU does not have', function () {
    $user = stocktakeStaff('warehouse');
    $id = stocktakeStart($user, false);

    $this->actingAs($user)->postJson("/api/v1/warehouse/stocktakes/{$id}/lines", ['sku_id' => $this->sku->public_id, 'pack_code' => 'NOPE', 'pack_qty' => 1])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'pack_not_for_sku');
});

it('renders the stocktake page and resumes a session', function () {
    $user = stocktakeStaff('warehouse');
    $id = stocktakeStart($user, true);

    $this->actingAs($user)->get("/warehouse/stocktake?stocktake={$id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Warehouse/Stocktake', false)
            ->where('stocktake.id', $id)
            ->where('in_progress.0.id', $id)
            ->where('can_count', true)
            ->has('reasons', 7));
});

it('lets purchasing see stocktakes but not count', function () {
    $purchasing = stocktakeStaff('purchasing');
    $stocktake = Stocktake::factory()->create(['location_id' => $this->location->id]);

    expect($purchasing->can('viewAny', Stocktake::class))->toBeTrue()
        ->and($purchasing->can('view', $stocktake))->toBeTrue()
        ->and($purchasing->can('create', Stocktake::class))->toBeFalse()
        ->and($purchasing->can('update', $stocktake))->toBeFalse()
        ->and($purchasing->can('delete', $stocktake))->toBeFalse();

    $this->actingAs($purchasing)->get('/warehouse/stocktake')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Warehouse/Stocktake', false)->where('can_count', false));
});

it('keeps the stocktake page from customers', function () {
    $this->actingAs(User::factory()->create())->get('/warehouse/stocktake')->assertForbidden();
});
