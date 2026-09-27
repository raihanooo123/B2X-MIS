<?php

use App\Filament\Resources\TradeApplicationResource\Pages\ListTradeApplications;
use App\Models\B2bApplication;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Stored in UTC, shown in `app.display_timezone` (Europe/London): the
 * Filament defaults and the custom formatters must agree for one instant.
 */
it('shows Filament date columns in the display timezone while storing UTC', function () {
    $this->withoutVite();
    Role::factory()->create(['code' => 'admin', 'name' => 'Admin']);
    $admin = User::factory()->withTwoFactor()->create();
    RoleUser::create(['role_id' => Role::query()->where('code', 'admin')->value('id'), 'user_id' => $admin->id]);
    $application = B2bApplication::factory()->create(['submitted_at' => Carbon::parse('2026-09-28 22:38:48', 'UTC')]);
    $this->actingAs($admin);

    // 22:38:48 UTC is 23:38:48 in London during British Summer Time.
    Livewire::test(ListTradeApplications::class)
        ->assertTableColumnFormattedStateSet('submitted_at', 'Sep 28, 2026 23:38:48', $application);

    expect(config('app.timezone'))->toBe('UTC')
        ->and((string) DB::table('b2b_applications')->where('id', $application->id)->selectRaw("to_char(submitted_at AT TIME ZONE 'UTC', 'HH24:MI:SS') AS t")->value('t'))->toBe('22:38:48');
});

it('formats custom text in the same zone as Filament, through summer and winter time', function () {
    $summer = Carbon::parse('2026-09-28 22:38:48', 'UTC');
    $winter = Carbon::parse('2026-12-01 22:38:48', 'UTC');

    expect(DisplayTime::format($summer))->toBe('28 Sep 2026 23:38')
        ->and(DisplayTime::format($winter))->toBe('1 Dec 2026 22:38')
        ->and(DisplayTime::format(Carbon::parse('2026-09-28 23:30:00', 'UTC'), DisplayTime::DATE))->toBe('29 Sep 2026')
        ->and(DisplayTime::format(null))->toBe('—')
        ->and($summer->getTimezone()->getName())->toBe('UTC');

    config(['app.display_timezone' => 'UTC']);
    expect(DisplayTime::format($summer))->toBe('28 Sep 2026 22:38');
});

it('tells every storefront page which zone to show times in', function () {
    $this->withoutVite();

    $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('display_timezone', 'Europe/London'));
});
