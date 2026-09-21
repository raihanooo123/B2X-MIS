<?php

use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CLAUDE.md: Filament resources "go through the same Policies as
 * everything else — no separate authorisation path." UserPolicy is
 * exercised directly here (not just via canAccessPanel()) so a
 * regression in either the policy or the model's delegation to it is
 * caught precisely.
 */
it('lets a user holding a staff role access the admin panel', function () {
    $user = User::factory()->create();
    $role = Role::factory()->admin()->create();
    RoleUser::create(['role_id' => $role->id, 'user_id' => $user->id]);

    expect($user->can('accessAdminPanel', User::class))->toBeTrue()
        ->and($user->canAccessPanel(Filament::getDefaultPanel()))->toBeTrue();
});

it('blocks a user holding no role from the admin panel', function () {
    $user = User::factory()->create();

    expect($user->can('accessAdminPanel', User::class))->toBeFalse()
        ->and($user->canAccessPanel(Filament::getDefaultPanel()))->toBeFalse();
});

it('blocks a user whose only role was revoked', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create(['code' => 'rep']);
    RoleUser::create(['role_id' => $role->id, 'user_id' => $user->id]);

    // RoleUser's composite (role_id, user_id) PK means a plain
    // ->delete() on a loaded instance can't locate its own row —
    // targeted by the natural key instead, same discipline StockLevel's
    // own composite-identity rows require.
    RoleUser::query()->where('role_id', $role->id)->where('user_id', $user->id)->delete();

    expect($user->canAccessPanel(Filament::getDefaultPanel()))->toBeFalse();
});
