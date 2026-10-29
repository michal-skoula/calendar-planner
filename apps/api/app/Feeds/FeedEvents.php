<?php

namespace App\Feeds;

use App\Models\CalendarFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The read path. Everything the browser sees of a subscribed calendar comes
 * from here, and nothing here opens a socket.
 *
 * That is the rule the whole feature rests on: a feed whose server is down,
 * slow, or gone changes nothing about rendering a week. The last payload that
 * parsed is in the row, and this reads it.
 *
 * Expansions are cached on the payload hash, so a refreshed feed misses the
 * cache without anything having to invalidate it, and a stale entry is
 * unreachable rather than wrong.
 */
final class FeedEvents
{
    public function __construct(private readonly FeedExpander $expander) {}

    /**
     * Read-only occurrences for everything overlapping [$from, $to).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pad = (int) config('feeds.window_pad_days');
        $start = $from->subDays($pad);
        $end = $to->addDays($pad);

        $events = [];

        foreach (CalendarFeed::renderable() as $feed) {
            foreach ($this->cached($feed, $start, $end) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cached(CalendarFeed $feed, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = sprintf(
            'feed-events:%d:%s:%s:%s',
            $feed->getKey(),
            (string) $feed->payload_hash,
            $start->toDateString(),
            $end->toDateString(),
        );

        return Cache::remember(
            $key,
            (int) config('feeds.cache_ttl'),
            fn (): array => $this->build($feed, $start, $end),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function build(CalendarFeed $feed, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $zone = (string) config('caldav.display_timezone');

        return array_map(
            fn (FeedOccurrence $occurrence): array => self::toEvent($feed, $occurrence, $zone),
            $this->expander->expand($feed, $start, $end)->occurrences,
        );
    }

    /**
     * The same wall-clock convention tasks use (see App\Support\WallClock): no
     * offset, in the display timezone, so the grid reads Prague time whatever
     * the client's machine is set to.
     *
     * The shape is client-neutral on purpose. Each client maps it onto its own
     * calendar widget, and the web app's mapping is where read-only is enforced
     * on the grid. Nothing in the API can write to a feed occurrence anyway.
     *
     * @return array<string, mixed>
     */
    private static function toEvent(CalendarFeed $feed, FeedOccurrence $occurrence, string $zone): array
    {
        $start = $occurrence->startsAt->setTimezone($zone);
        $end = $occurrence->endsAt->setTimezone($zone);

        return [
            'id' => $occurrence->eventId(),
            'feed_id' => (int) $feed->getKey(),
            'feed_name' => $feed->name,
            'color' => $feed->color,
            'title' => $occurrence->title,
            'location' => $occurrence->location,
            'busy' => $occurrence->isBusy,
            'all_day' => $occurrence->isAllDay,
            'start' => $occurrence->isAllDay
                ? $occurrence->startsAt->toDateString()
                : $start->format('Y-m-d\TH:i:s'),
            'end' => $occurrence->isAllDay
                ? $occurrence->endsAt->toDateString()
                : $end->format('Y-m-d\TH:i:s'),
        ];
    }
}
