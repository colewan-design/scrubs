<?php

namespace App\Providers;

use App\Services\Shipping\StallionProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ShippingService looks for a live rating provider under this binding
        // and falls back to table rates when it is absent. Registering it only
        // when a key exists means an unconfigured install cannot accidentally
        // route checkout through a carrier that will refuse every request.
        if (config('services.stallion.key')) {
            $this->app->bind(
                'shipping.provider.stallion',
                fn () => new StallionProvider,
            );
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
