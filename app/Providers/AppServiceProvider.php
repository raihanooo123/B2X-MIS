<?php

namespace App\Providers;

use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\StripeGateway;
use App\Domain\Documents\NullPdfRenderer;
use App\Domain\Documents\PdfRenderer;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
