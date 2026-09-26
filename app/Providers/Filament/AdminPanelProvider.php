<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Models\GoodsReceipt;
use App\Models\Shipment;
use App\Models\Stocktake;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No Filament login page: staff sign in at /login like everyone
            // else, so the lockout (05.13 §6.2) and the 2FA challenge
            // (§12) have one implementation. Unauthenticated panel requests
            // fall through to the named `login` route.
            ->brandName('B2X Wholesale')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->font('Inter')
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->spaUrlExceptions([url('/warehouse/*')])
            ->globalSearch()
            ->navigationGroups([
                'Catalogue',
                'Pricing',
                'Inventory',
                'Sales',
                'Warehouse',
                'Accounts',
                'Settings',
            ])
            ->navigationItems([
                NavigationItem::make('Goods in')
                    ->group('Warehouse')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->sort(10)
                    ->url(fn (): string => route('warehouse.goods-in'))
                    ->visible(fn (): bool => Gate::allows('viewAny', GoodsReceipt::class)),
                NavigationItem::make('Picking')
                    ->group('Warehouse')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->sort(20)
                    ->url(fn (): string => route('warehouse.pick-list'))
                    ->visible(fn (): bool => Gate::allows('viewAny', Shipment::class)),
                NavigationItem::make('Dispatch')
                    ->group('Warehouse')
                    ->icon('heroicon-o-truck')
                    ->sort(30)
                    ->url(fn (): string => route('warehouse.dispatch'))
                    ->visible(fn (): bool => Gate::allows('viewAny', Shipment::class)),
                NavigationItem::make('Stocktake')
                    ->group('Warehouse')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->sort(40)
                    ->url(fn (): string => route('warehouse.stocktake'))
                    ->visible(fn (): bool => Gate::allows('viewAny', Stocktake::class)),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // 07 §6.1: staff idle limits and mandatory 2FA apply in the
                // panel exactly as on every other route (05.13 §12.1, §13).
                EnforceSessionPolicy::class,
                RequireStaffTwoFactor::class,
            ]);
    }
}
