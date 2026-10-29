<?php

namespace App\Feeds;

use App\Models\CalendarFeed;
use App\Models\SyncEvent;
use App\Services\SyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Turns a feed URL into a stored calendar, or into a recorded failure.
 *
 * Nothing on the request path calls this. It runs from planner:feeds:refresh on
 * the host's timer, and from the Refresh button on the feeds page, which is the
 * one place a person has asked to wait.
 *
 * The contract the rest of the feature leans on: a failed fetch leaves
 * `payload`, `payload_hash`, `etag` and `last_modified` exactly as they were. A
 * feed whose server is down keeps drawing yesterday's events, and the only
 * visible change is a red dot and a line on the sync page.
 */
final class FeedFetcher
{
    /** How far either side of today the probe expansion looks, to count series. */
    private const PROBE_BEFORE_DAYS = 7;

    private const PROBE_AFTER_DAYS = 60;

    public function __construct(
        private readonly FeedGuard $guard,
        private readonly FeedExpander $expander,
        private readonly SyncLog $sync,
    ) {}

    public function fetch(CalendarFeed $feed): FeedFetchResult
    {
        $feed->last_fetched_at = CarbonImmutable::now();

        try {
            $url = $this->guard->assert($feed->url);
        } catch (FeedRefused $e) {
            return $this->failed($feed, $e->getMessage());
        }

        try {
            $response = Http::withHeaders($this->headers($feed))
                ->connectTimeout((int) config('feeds.timeout'))
                ->timeout((int) config('feeds.timeout'))
                ->withOptions([
                    'stream' => true,
                    'decode_content' => true,
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => FeedUrl::allowedSchemes(),
                        // The hop is checked before the body is read, so a 302
                        // into this network is refused on the redirect itself.
                        'on_redirect' => function ($request, $response, $uri): void {
                            $this->guard->assertReachableOnly((string) $uri);
                        },
                    ],
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            return $this->failed($feed, 'could not connect: '.self::briefly($e));
        } catch (Throwable $e) {
            return $this->failed($feed, self::briefly($e));
        }

        $status = $response->status();

        // Nothing changed since the last fetch. This is what most refreshes do,
        // and it is why etag and last_modified are stored at all.
        if ($status === 304) {
            return $this->succeeded($feed, $status, FeedFetchResult::Unchanged);
        }

        if ($status < 200 || $status >= 300) {
            return $this->failed($feed, "answered {$status}", $status);
        }

        try {
            $body = self::readCapped(
                $response->toPsrResponse()->getBody(),
                (int) config('feeds.max_bytes'),
            );
            $calendar = self::parse($body);
        } catch (FeedRefused $e) {
            return $this->failed($feed, $e->getMessage(), $status);
        } catch (Throwable $e) {
            return $this->failed($feed, 'could not read the calendar: '.self::briefly($e), $status);
        }

        $hash = hash('sha256', $body);
        $etag = $response->header('ETag') ?: null;
        $lastModified = $response->header('Last-Modified') ?: null;

        if ($hash === $feed->payload_hash) {
            // A provider that sends no validators re-sends the whole body every
            // time. Treat an identical one as the 304 it should have been, so
            // `updated_at` does not churn and the planner stops refetching.
            $feed->etag = $etag;
            $feed->last_modified = $lastModified;

            return $this->succeeded($feed, $status, FeedFetchResult::Unchanged);
        }

        $feed->payload = $body;
        $feed->payload_hash = $hash;
        $feed->payload_bytes = strlen($body);
        $feed->etag = $etag;
        $feed->last_modified = $lastModified;

        $this->countSeries($feed, $calendar);

        return $this->succeeded($feed, $status, FeedFetchResult::Changed);
    }

    /**
     * Expand once over a window around today, so the feeds page can say how many
     * series this calendar holds and how many the expander could not walk.
     *
     * Doing it here rather than on render means a series that cannot be read is
     * reported when the feed arrives, not whenever someone happens to navigate
     * to the week it would have appeared in.
     */
    private function countSeries(CalendarFeed $feed, VCalendar $calendar): void
    {
        $now = CarbonImmutable::now();

        $expansion = $this->expander->expand(
            $feed,
            $now->subDays(self::PROBE_BEFORE_DAYS),
            $now->addDays(self::PROBE_AFTER_DAYS),
            $calendar,
        );

        $feed->series_count = $expansion->seriesSeen;
        $feed->dropped_count = count($expansion->dropped);

        foreach ($expansion->dropped as $uid => $reason) {
            $this->record('feed_series_dropped', $feed, "{$uid}: {$reason}");
        }
    }

    /**
     * Read a response body with a hard ceiling, without buffering past it.
     *
     * @throws FeedRefused
     */
    private static function readCapped(StreamInterface $stream, int $max): string
    {
        $body = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(65536);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $max) {
                throw new FeedRefused(
                    'the calendar is larger than '.round($max / 1024 / 1024, 1).' MB'
                );
            }
        }

        return $body;
    }

    /**
     * Prove the body is a calendar before it is allowed to replace a good one.
     *
     * A provider whose link has expired answers 200 with an HTML sign-in page,
     * which is indistinguishable from success at the HTTP layer and would
     * otherwise blank the calendar.
     *
     * An empty but well-formed VCALENDAR is accepted: a shared calendar with
     * nothing in it is a real thing, and refusing it would mark it as
     * permanently broken.
     *
     * @throws FeedRefused
     */
    private static function parse(string $body): VCalendar
    {
        if (! str_contains(strtoupper(substr($body, 0, 2048)), 'BEGIN:VCALENDAR')) {
            throw new FeedRefused('that URL did not answer with a calendar');
        }

        try {
            $calendar = Reader::read($body, Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            throw new FeedRefused('that calendar could not be parsed: '.self::briefly($e));
        }

        if (! $calendar instanceof VCalendar) {
            throw new FeedRefused('that URL answered with something other than a calendar');
        }

        return $calendar;
    }

    private function succeeded(CalendarFeed $feed, int $status, FeedFetchResult $result): FeedFetchResult
    {
        $feed->last_success_at = $feed->last_fetched_at;
        $feed->last_status = $status;
        $feed->last_error = null;
        $feed->save();

        if ($result === FeedFetchResult::Changed) {
            $this->record('feed_updated', $feed, $feed->series_count.' series');
        }

        return $result;
    }

    private function failed(CalendarFeed $feed, string $why, ?int $status = null): FeedFetchResult
    {
        // Deliberately not clearing the payload. See the class docblock.
        $feed->last_status = $status;
        $feed->last_error = mb_substr($why, 0, 240);
        $feed->save();

        $this->record('feed_failed', $feed, $feed->last_error);

        return FeedFetchResult::Failed;
    }

    private function record(string $kind, CalendarFeed $feed, ?string $detail = null): void
    {
        $this->sync->record(
            kind: $kind,
            title: $feed->name,
            detail: $detail,
            source: SyncEvent::SOURCE_FEED,
        );
    }

    /** @return array<string, string> */
    private function headers(CalendarFeed $feed): array
    {
        $headers = [
            'Accept' => 'text/calendar, text/plain;q=0.5, */*;q=0.1',
            'User-Agent' => 'Planner/1.0 (+calendar subscription)',
        ];

        if ($feed->etag !== null) {
            $headers['If-None-Match'] = $feed->etag;
        }

        if ($feed->last_modified !== null) {
            $headers['If-Modified-Since'] = $feed->last_modified;
        }

        return $headers;
    }

    /** One line, no stack trace, short enough for a column and a list item. */
    private static function briefly(Throwable $e): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');

        return $message === '' ? $e::class : mb_substr($message, 0, 160);
    }
}
