<?php

namespace App\Providers\Filament;

use App\Domain\Storefront\Branding;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Models\CollectionBooking;
use App\Models\GoodsReceipt;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Stocktake;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            // No Filament login page: staff sign in at /login like everyone
            // else, so the lockout (05.13 §6.2) and the 2FA challenge
            // (§12) have one implementation. Unauthenticated panel requests
            // fall through to the named `login` route.
            ->path('admin')
            // White-label (05.15 §3.1): the business's own name, as set on
            // the storefront settings page. Read once per request.
            ->brandName(fn (): string => once(fn (): string => Branding::current()->name))
            ->colors([
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode()
            ->maxContentWidth('screen-2xl')
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->spaUrlExceptions([url('/warehouse/*'), url('/staff/*')])
            ->globalSearch()
            // Day-to-day selling first, then stock and buying, then the
            // back office. Groups carry the icons; Filament allows icons on
            // a group or on its items, not both.
            ->navigationGroups([
                NavigationGroup::make('Sales')->icon('heroicon-o-shopping-bag'),
                NavigationGroup::make('Customers')->icon('heroicon-o-user-group'),
                NavigationGroup::make('Catalogue')->icon('heroicon-o-squares-2x2'),
                NavigationGroup::make('Pricing')->icon('heroicon-o-currency-pound'),
                NavigationGroup::make('Inventory')->icon('heroicon-o-archive-box'),
                NavigationGroup::make('Warehouse')->icon('heroicon-o-truck'),
                NavigationGroup::make('Collections')->icon('heroicon-o-building-storefront'),
                NavigationGroup::make('Purchasing')->icon('heroicon-o-shopping-cart'),
                NavigationGroup::make('Accounts')->icon('heroicon-o-banknotes'),
                NavigationGroup::make('Content')->icon('heroicon-o-document-text')->collapsed(),
                NavigationGroup::make('Settings')->icon('heroicon-o-cog-6-tooth')->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Order cancellations')
                    ->group('Sales')
                    ->sort(30)
                    ->url(fn (): string => route('staff.order-cancellations'))
                    ->visible(fn (): bool => Gate::allows('recordUndispatchedCancellation', Order::class)),
                NavigationItem::make('Goods in')
                    ->group('Warehouse')
                    ->sort(10)
                    ->url(fn (): string => route('warehouse.goods-in'))
                    ->visible(fn (): bool => Gate::allows('viewAny', GoodsReceipt::class)),
                NavigationItem::make('Picking')
                    ->group('Warehouse')
                    ->sort(20)
                    ->url(fn (): string => route('warehouse.pick-list'))
                    ->visible(fn (): bool => Gate::allows('viewAny', Shipment::class)),
                NavigationItem::make('Dispatch')
                    ->group('Warehouse')
                    ->sort(30)
                    ->url(fn (): string => route('warehouse.dispatch'))
                    ->visible(fn (): bool => Gate::allows('viewAny', Shipment::class)),
                // 05.6 §7A.6: the counter is a React screen, like the other warehouse screens.
                NavigationItem::make('Collections counter')
                    ->group('Collections')
                    ->sort(10)
                    ->url(fn (): string => route('warehouse.collections'))
                    ->visible(fn (): bool => Gate::allows('viewAny', CollectionBooking::class)),
                NavigationItem::make('Stocktake')
                    ->group('Warehouse')
                    ->sort(40)
                    ->url(fn (): string => route('warehouse.stocktake'))
                    ->visible(fn (): bool => Gate::allows('viewAny', Stocktake::class)),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
