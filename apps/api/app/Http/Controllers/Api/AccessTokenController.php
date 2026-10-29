<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessTokenResource;
use App\Support\WallClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /api/tokens — bearer tokens for everything that is not the web app: the iOS
 * Shortcut, MCP clients, scripts, a desktop app.
 *
 * Sanctum does the real work. It stores a hash of each secret, checks
 * `expires_at` on every request, and records `last_used_at`. This controller
 * only lists, creates and deletes rows, and the routes allow it from a browser
 * session alone (App\Http\Middleware\RequireBrowserSession).
 */
class AccessTokenController extends Controller
{
    /** GET /api/tokens — newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AccessTokenResource::collection(
            $request->user()->tokens()->latest('id')->get()
        );
    }

    /**
     * POST /api/tokens
     *
     * The plain-text secret is in this response and nowhere else, ever.
     * `expires_on` is a date: the token works through the end of that day in
     * the planner's timezone. Leave it out for a token that never expires.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $expiresAt = isset($data['expires_on'])
            ? WallClock::parse($data['expires_on'])->endOfDay()->utc()
            : null;

        $new = $request->user()->createToken(trim($data['name']), ['*'], $expiresAt);

        return response()->json([
            'data' => [
                'token' => $new->plainTextToken,
                'access_token' => new AccessTokenResource($new->accessToken),
            ],
        ], 201);
    }

    /** DELETE /api/tokens/{token} — anything using it gets a 401 from now on. */
    public function destroy(Request $request, int $token): Response
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return response()->noContent();
    }
}
