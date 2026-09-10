<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\WholesaleController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| API v1
|---------------------------------------------------------------------------
| Prefixed with /api/v1 (see bootstrap/app.php). In production Nginx serves
| this from the same origin as the Nuxt storefront, so Sanctum authenticates
| with ordinary first-party cookies.
*/

// ---- Public catalogue. Anyone may browse without an account (§3). ----------
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/wholesale', WholesaleController::class);

// ---- Cart. Works for guests and members alike; the quote differs, not the API.
Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'add']);
    Route::patch('/items', [CartController::class, 'update']);
    Route::delete('/items', [CartController::class, 'remove']);
});

// ---- Authentication (§3, §8) ----------------------------------------------
// Throttled: these are the endpoints worth attacking.
Route::middleware('throttle:6,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Password reset (§8). Throttled alongside login: an unthrottled reset
    // endpoint is both a mail bomb and an account-enumeration oracle.
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);

// ---- Social sign-in (§3) ---------------------------------------------------
// Browser redirects rather than JSON: the customer leaves for the provider and
// comes back. Inert until credentials are configured, and the controller says
// so rather than erroring.
// Lets the storefront render only the buttons that will actually work.
Route::get('/auth/providers', [SocialAuthController::class, 'available']);

Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['google']);
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['google']);

// ---- Email verification (§8) -----------------------------------------------
// The confirmation link is clicked in a mail client, often on a device with no
// session, so this one is public and leans on the URL signature instead. The
// route name is Laravel's own so URL::temporarySignedRoute resolves it.
Route::get('/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->whereNumber('id')
    ->name('verification.verify');

// ---- Store details and policy pages (§1, §11) ------------------------------
Route::get('/content/store', [ContentController::class, 'store']);
Route::get('/content/policies/{slug}', [ContentController::class, 'policy']);

// ---- Checkout (§4, §5, §6, §7) ---------------------------------------------
// Guests may check out: an account is what unlocks wholesale pricing, not what
// permits a purchase. Both endpoints price server-side from the cart.
Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:12,1');

// Order lookup proves ownership itself — signed-in owner, or the email the
// order was placed with — so a guest can see their own confirmation.
Route::get('/orders/{order}', [OrderController::class, 'show']);

// ---- Authenticated customer account (§8) ----------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

    // Contact details and password. Both re-issue the account payload so the
    // storefront never has to guess what changed.
    Route::patch('/account/profile', [AccountController::class, 'updateProfile']);
    Route::put('/account/password', [AccountController::class, 'updatePassword'])
        ->middleware('throttle:6,1');

    // Re-sending is throttled harder than it looks like it needs to be: the
    // recipient is fixed, but an unthrottled resend is still a way to make our
    // mail server look like a spammer.
    Route::post('/account/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,10');

    // Saved addresses. Scoped to the signed-in customer inside the controller,
    // so {address} is an id within their own book and nobody else's.
    Route::get('/account/addresses', [AddressController::class, 'index']);
    Route::post('/account/addresses', [AddressController::class, 'store']);
    Route::patch('/account/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
    Route::delete('/account/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');
});
