<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\PlannerIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signing in. There is one account and one password, shared with CalDAV.
 *
 * Two ways in, both handled by Sanctum's `auth:sanctum` guard afterwards:
 *
 * - The web app signs in with a session cookie (POST /api/auth/login). Sanctum
 *   treats requests from the domains in SANCTUM_STATEFUL_DOMAINS as a browser
 *   and runs the session and CSRF middleware for them; see bootstrap/app.php.
 * - Native clients (a desktop or mobile app, the iOS Shortcut, MCP) send a
 *   bearer token, minted by POST /api/auth/tokens or `php artisan planner:token`.
 */
class AuthController extends Controller
{
    /** POST /api/auth/login */
    public function login(Request $request): UserResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Sanctum only starts a session for a request from a stateful domain.
        // Anything else has no cookie jar to put one in.
        abort_unless($request->hasSession(), 400,
            'Session sign-in is for the web app. Other clients use POST /api/auth/tokens.');

        $user = $this->attempt($credentials['email'], $credentials['password']);

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return new UserResource($user);
    }

    /** POST /api/auth/tokens — for clients that cannot hold a cookie. */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = $this->attempt($data['email'], $data['password']);

        return response()->json([
            'data' => [
                'token' => $user->createToken($data['device_name'])->plainTextToken,
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    /**
     * POST /api/auth/logout
     *
     * Ends whichever credential made the request: a bearer token is revoked, a
     * browser session is invalidated.
     */
    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** GET /api/me — who is signed in, and the settings a client draws with. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($request->user()),
                'timezone' => (string) config('caldav.display_timezone'),
                'default_duration_min' => (int) config('caldav.default_duration_min'),
                'feed_refresh_min' => (int) config('feeds.refresh_min'),
            ],
        ]);
    }

    /**
     * Resolve the one account case-insensitively, then check the password.
     * Matching on `email` directly would reject the same address typed with a
     * capital letter, because SQLite compares TEXT case-sensitively — the same
     * trap that breaks CalDAV from a phone.
     */
    private function attempt(string $email, string $password): User
    {
        $user = PlannerIdentity::resolve($email);

        if ($user === null || ! Auth::guard('web')->validate(['id' => $user->getKey(), 'password' => $password])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return $user;
    }
}
