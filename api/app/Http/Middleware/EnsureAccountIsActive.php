<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account loses its session on its very next request — §9.
 *
 * AuthController::login already refuses a suspended customer, but that only
 * covers accounts suspended *before* they signed in. Suspension is something
 * an administrator does to a customer who is very likely signed in at that
 * moment, and without this the existing session keeps shopping until it
 * expires. The check has to live on the request path, not the login path.
 *
 * Runs on the whole API group rather than behind auth:sanctum: an unauthorised
 * cart or catalogue request should also stop carrying a dead session.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'This account has been suspended. Please contact us.',
                'status' => 'suspended',
            ], 403);
        }

        return $next($request);
    }
}
