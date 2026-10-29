<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses requests authenticated with a bearer token.
 *
 * Used on the token management endpoints. If a token could create tokens, one
 * leaked token could mint itself a replacement before you revoke it, and
 * revoking would never be the end of it. Only the signed-in web app (or
 * `php artisan planner:token` on the box) can.
 */
class RequireBrowserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->currentAccessToken() instanceof PersonalAccessToken) {
            abort(403, 'Access tokens can only be managed from the web app, not with a token.');
        }

        return $next($request);
    }
}
