<?php

namespace App\Feeds;

use App\Models\CalendarFeed;
use Illuminate\Validation\ValidationException;

/**
 * Subscribing to a calendar someone else owns.
 *
 * Adding one fetches it straight away, because the alternative is a page that
 * says nothing for up to fifteen minutes and gives no way to tell a working URL
 * from a typo. Every later fetch happens on the host's timer.
 */
final class FeedSubscriptions
{
    public function __construct(
        private readonly FeedGuard $guard,
        private readonly FeedFetcher $fetcher,
    ) {}

    /**
     * @throws ValidationException when the URL is refused or already subscribed
     */
    public function subscribe(string $url, ?string $name, string $color): CalendarFeed
    {
        try {
            $normalised = $this->guard->assert($url);
        } catch (FeedRefused $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $hash = CalendarFeed::hashFor($normalised);

        if (CalendarFeed::query()->where('url_hash', $hash)->exists()) {
            throw ValidationException::withMessages([
                'url' => 'That calendar is already subscribed to.',
            ]);
        }

        $name = trim((string) $name);

        $feed = CalendarFeed::query()->create([
            'name' => $name !== '' ? $name : FeedUrl::suggestName($normalised),
            'url' => $normalised,
            'url_hash' => $hash,
            'color' => strtolower($color),
            'refresh_min' => (int) config('feeds.refresh_min'),
            'sort_order' => (int) CalendarFeed::query()->max('sort_order') + 1,
        ]);

        // A failed first fetch still leaves the subscription in place: the
        // feed's own status line says what went wrong, and the timer retries.
        $this->fetcher->fetch($feed);

        return $feed->refresh();
    }
}
