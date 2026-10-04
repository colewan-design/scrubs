<?php

namespace App\Providers;

use App\Services\Shipping\StallionProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Address lookup is paid for per request, so it gets two ceilings. The
        // minute limit is generous enough that nobody typing an address meets
        // it; the daily one is what bounds the bill if an account is scripted.
        RateLimiter::for('address-lookup', function (Request $request) {
            $who = $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(60)->by('address-lookup:minute:'.$who),
                Limit::perDay(600)->by('address-lookup:day:'.$who),
            ];
        });
    }
}
