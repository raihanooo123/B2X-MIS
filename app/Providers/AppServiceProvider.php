<?php

namespace App\Providers;

use App\Domain\Audit\AuditContext;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\StripeGateway;
use App\Domain\Documents\NullPdfRenderer;
use App\Domain\Documents\PdfRenderer;
use App\Models\Category;
use App\Models\CmsPageVersion;
use App\Models\Product;
use App\Observers\SitemapCacheObserver;
use App\Support\DisplayTime;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 07 §6.4: card payments go through Stripe; tests bind a fake.

        $this->app->bind(PaymentGateway::class, function () {
            return new StripeGateway(
                new StripeClient((string) config('services.stripe.secret'))
            );
        });
        // $this->app->bind(PaymentGateway::class, StripeGateway::class);

        // No PDF worker yet (docs/12-pdf-worker.md): documents are issued
        // without an archived PDF until one is bound here.
        $this->app->bind(PdfRenderer::class, NullPdfRenderer::class);

        // 07 §6.5: the client IP and user agent staff audit entries record,
        // per request. None in a console command or queue worker.
        $this->app->scoped(AuditContext::class, fn ($app) => $app->runningInConsole() && ! $app->runningUnitTests()
            ? AuditContext::none()
            : AuditContext::fromRequest($app['request']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One display timezone for every Filament date (tables, infolists,
        // date-time pickers); storage stays UTC (DisplayTime).
        TextColumn::configureUsing(fn (TextColumn $column) => $column->timezone(DisplayTime::zone()));
        TextEntry::configureUsing(fn (TextEntry $entry) => $entry->timezone(DisplayTime::zone()));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker->timezone(DisplayTime::zone()));

        // 05.11 §6.1: the cached sitemap follows the catalogue and the pages.
        Product::observe(SitemapCacheObserver::class);
        Category::observe(SitemapCacheObserver::class);
        CmsPageVersion::observe(SitemapCacheObserver::class);
    }
}
