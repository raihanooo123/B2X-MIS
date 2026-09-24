<?php

namespace App\Providers;

use App\Domain\Billing\PaymentGateway;
use App\Domain\Billing\StripeGateway;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
