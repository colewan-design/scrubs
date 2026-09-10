<?php

use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA authentication. In production Nuxt and Laravel are served
        // from one origin (Nginx routes /api to PHP-FPM), so this uses ordinary
        // first-party cookies: no CORS, no tokens in the browser, CSRF-protected.
        $middleware->statefulApi();

        // A customer suspended by the admin (§9) must lose the session they are
        // already holding, not keep it until it expires.
        $middleware->api(append: [EnsureAccountIsActive::class]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients get JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
