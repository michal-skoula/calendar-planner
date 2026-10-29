<?php

namespace App\Http\Controllers\Api;

use App\Feeds\FeedEvents;
use App\Feeds\FeedFetcher;
use App\Feeds\FeedSubscriptions;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeedResource;
use App\Models\CalendarFeed;
use App\Support\WallClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /api/feeds — subscribed calendars, and the read-only events they contribute.
 *
 * None of this touches `tasks` or a sabre table. See app/Feeds for the fetch
 * and expansion machinery; this controller only exposes it.
 */
class FeedController extends Controller
{
    private const COLOR = ['string', 'regex:/^#[0-9a-fA-F]{6}$/'];

    /** GET /api/feeds */
    public function index(): AnonymousResourceCollection
    {
        return FeedResource::collection(CalendarFeed::query()->ordered()->get());
    }

    /**
     * POST /api/feeds
     *
     * Fetches straight away. A failed first fetch still answers 201; the
     * returned feed's `last_error` says what went wrong.
     */
    public function store(Request $request, FeedSubscriptions $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'name' => ['nullable', 'string', 'max:120'],
            'color' => ['required', ...self::COLOR],
        ]);

        $feed = $subscriptions->subscribe($data['url'], $data['name'] ?? null, $data['color']);

        return (new FeedResource($feed))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/feeds/{feed}
     *
     * Turning a feed off stops it being drawn and stops it being fetched. The
     * payload stays, so turning it back on is immediate rather than a wait.
     */
    public function update(Request $request, CalendarFeed $feed): FeedResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'color' => ['sometimes', 'required', ...self::COLOR],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $feed->name = trim($data['name']);
        }

        if (array_key_exists('color', $data)) {
            $feed->color = strtolower($data['color']);
        }

        if (array_key_exists('enabled', $data)) {
            $feed->is_enabled = $data['enabled'];
        }

        $feed->save();

        return new FeedResource($feed);
    }

    /** DELETE /api/feeds/{feed} */
    public function destroy(CalendarFeed $feed): Response
    {
        $feed->delete();

        return response()->noContent();
    }

    /** POST /api/feeds/{feed}/refresh — fetch now instead of waiting for the timer. */
    public function refresh(CalendarFeed $feed, FeedFetcher $fetcher): FeedResource
    {
        $fetcher->fetch($feed);

        return new FeedResource($feed->refresh());
    }

    /**
     * GET /api/feed-events?from=&to=
     *
     * Never opens a socket: FeedEvents reads the last payload that parsed, so a
     * dead feed cannot slow down drawing a week.
     */
    public function events(Request $request, FeedEvents $events): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        return response()->json([
            'data' => $events->forWindow(
                WallClock::parse($data['from'])->utc(),
                WallClock::parse($data['to'])->utc(),
            ),
        ]);
    }
}
