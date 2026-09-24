<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\RequireStaffTwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
            ->globalSearch()
            ->navigationGroups([
                'Catalogue',
                'Pricing',
                'Inventory',
                'Sales',
                'Settings',
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
