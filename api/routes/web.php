<?php

use App\Http\Controllers\Api\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ---- Social sign-in (§3) ---------------------------------------------------
// Browser redirects rather than JSON: the customer leaves for the provider and
// comes back. Inert until credentials are configured, and the controller says
// so rather than erroring.
//
// These are web routes because the callback needs a session. Socialite keeps
// its OAuth `state` there, and Sanctum's stateful API only starts a session
// when the Referer is the storefront — a customer arriving from
// accounts.google.com is not, so under the api group every Google sign-in
// failed. The paths stay under /api/v1 so the redirect URI registered with
// Google, and Nginx sending /api to PHP, are unchanged.
Route::prefix('api/v1')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google']);
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google']);
});
