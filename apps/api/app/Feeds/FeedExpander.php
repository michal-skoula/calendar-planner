<?php

namespace App\Feeds;

use App\Models\CalendarFeed;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Settings;
use Throwable;

/**
 * Turns one feed's stored iCalendar into the occurrences a window needs.
 *
 * This is the only place in the application that reads a subscribed calendar,
 * the way App\Dav\VEventMapper is the only place that reads a task's own one.
 * Nothing above App\Feeds parses an .ics.
 *
 * Why this exists rather than Sabre\VObject\Component\VCalendar::expand(), which
 * implements the same algorithm correctly: expand() groups every recurring UID
 * in the document and iterates them in a single loop, so one series it cannot
 * walk throws and the whole feed comes back empty. EventIterator throws past
 * Settings::$maxRecurrences, which defaults to 3500, and reaching a window
 * costs one iteration per occurrence — a daily standup created in 2015 is about
 * 4,000 iterations by today. One such event in a shared work calendar and none
 * of the calendar renders.
 *
 * So: group by UID first, walk each series inside its own try/catch, and report
 * the ones that failed instead of losing the ones that did not.
 */
final class FeedExpander
{
    /**
     * Iterations allowed per series, covering both reaching the window and
     * walking through it. Generous enough for a decade of a daily event and
     * small enough that FREQ=SECONDLY since 1970 stops rather than hangs.
     */
    private const SERIES_BUDGET = 20_000;

    /** A ceiling on one expansion, so a pathological feed cannot exhaust memory. */
    private const MAX_OCCURRENCES = 10_000;

    /**
     * Expand everything overlapping the half-open window [$from, $to).
     *
     * Pass $calendar when the document is already parsed — the fetcher has one
     * in hand and parsing a megabyte twice is the most expensive thing here.
     */
    public function expand(
        CalendarFeed $feed,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?VCalendar $calendar = null,
    ): FeedExpansion {
        $calendar ??= self::parse($feed->payload);

        if ($calendar === null) {
            return FeedExpansion::empty();
        }

        $zone = new DateTimeZone((string) config('caldav.display_timezone'));
        $windowStart = $from->toDateTimeImmutable();
        $windowEnd = $to->toDateTimeImmutable();

        $occurrences = [];
        $dropped = [];
        $series = self::groupByUid($calendar);

        /*
         * Settings::$maxRecurrences is a public static, and FrankenPHP keeps the
         * worker process between requests. Leaving it changed would mean a DAV
         * write later in the same process running with recurrence limits off, so
         * the restore below is load-bearing rather than tidy. The budget above
         * replaces it, per series, which is the entire point of this class.
         */
        $previousLimit = Settings::$maxRecurrences;
        Settings::$maxRecurrences = -1;

        try {
            foreach ($series as $uid => $components) {
                if (count($occurrences) >= self::MAX_OCCURRENCES) {
                    $dropped[$uid] = 'the window already holds too many events';

                    continue;
                }

                try {
                    foreach (self::walk($components, $windowStart, $windowEnd, $zone) as $occurrence) {
                        $occurrences[] = self::toOccurrence($feed, $uid, $occurrence);
                    }
                } catch (Throwable $e) {
                    // One series, never the feed. A rule EventIterator cannot
                    // handle, a VTIMEZONE naming a zone PHP does not know, or a
                    // series that outran the budget all land here.
                    $dropped[$uid] = self::briefly($e);
                }
            }
        } finally {
            Settings::$maxRecurrences = $previousLimit;
        }

        usort(
            $occurrences,
            fn (FeedOccurrence $a, FeedOccurrence $b): int => $a->startsAt <=> $b->startsAt,
        );

        return new FeedExpansion($occurrences, count($series), $dropped);
    }

    /**
     * Every VEVENT in the document, keyed by UID.
     *
     * An override instance carrying RECURRENCE-ID belongs to the same group as
     * its master, which is what lets EventIterator apply it. An event with no
     * UID at all is its own group; feeds in the wild do contain them.
     *
     * @return array<string, array<int, VEvent>>
     */
    private static function groupByUid(VCalendar $calendar): array
    {
        $series = [];

        foreach ($calendar->select('VEVENT') as $event) {
            if (! $event instanceof VEvent) {
                continue;
            }

            // A cancelled event is still in the feed. It should not be drawn.
            if (strtoupper(trim((string) ($event->STATUS ?? ''))) === 'CANCELLED') {
                continue;
            }

            $uid = trim((string) ($event->UID ?? ''));

            if ($uid === '') {
                $uid = 'no-uid-'.spl_object_id($event);
            }

            $series[$uid][] = $event;
        }

        return $series;
    }

    /**
     * Walk one series, yielding [$start, $end, $event] per occurrence inside the
     * window.
     *
     * A series with no RRULE, RDATE or RECURRENCE-ID is one event and is tested
     * directly: building an iterator for it would cost more than it saves.
     *
     * @param  array<int, VEvent>  $components
     * @return \Generator<int, array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: VEvent, 3: bool}>
     */
    private static function walk(
        array $components,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeZone $zone,
    ): \Generator {
        if (! self::isRecurring($components)) {
            $event = $components[0];

            if ($event->DTSTART === null) {
                return;
            }

            if (! $event->isInTimeRange($from, $to)) {
                return;
            }

            $start = $event->DTSTART->getDateTime($zone);
            $end = self::endOf($event, $start, $zone);

            yield [$start, $end, $event, false];

            return;
        }

        $iterator = new EventIterator($components, null, $zone);
        $steps = 0;

        /*
         * fastForward() would do this, but it loops inside the library where the
         * only brake is the global limit this class has just switched off. The
         * budget has to be enforced where it can be counted.
         */
        while ($iterator->valid() && $iterator->getDtEnd() <= $from) {
            if (++$steps > self::SERIES_BUDGET) {
                throw new \RuntimeException(
                    'the series needs more than '.self::SERIES_BUDGET.' steps to reach this window'
                );
            }

            $iterator->next();
        }

        while ($iterator->valid() && $iterator->getDtStart() < $to) {
            if (++$steps > self::SERIES_BUDGET) {
                throw new \RuntimeException(
                    'the series produced more than '.self::SERIES_BUDGET.' occurrences'
                );
            }

            if ($iterator->getDtEnd() > $from) {
                yield [
                    $iterator->getDtStart(),
                    $iterator->getDtEnd(),
                    $iterator->getEventObject(),
                    true,
                ];
            }

            $iterator->next();
        }
    }

    private static function isRecurring(array $components): bool
    {
        foreach ($components as $event) {
            if ($event->RRULE !== null || $event->RDATE !== null || $event->{'RECURRENCE-ID'} !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 5545: a DATE-valued DTSTART with no DTEND lasts one day; a DATE-TIME
     * one has zero duration. The same reading App\Dav\VEventMapper uses.
     */
    private static function endOf(VEvent $event, DateTimeImmutable $start, DateTimeZone $zone): DateTimeImmutable
    {
        if ($event->DTEND !== null) {
            return $event->DTEND->getDateTime($zone);
        }

        if ($event->DURATION !== null) {
            return $start->add(DateTimeParser::parseDuration((string) $event->DURATION));
        }

        return self::isAllDay($event)
            ? $start->modify('+1 day')
            : $start;
    }

    private static function isAllDay(VEvent $event): bool
    {
        return $event->DTSTART !== null && ! $event->DTSTART->hasTime();
    }

    /**
     * @param  array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: VEvent, 3: bool}  $raw
     */
    private static function toOccurrence(CalendarFeed $feed, string $uid, array $raw): FeedOccurrence
    {
        [$start, $end, $event, $recurring] = $raw;

        $allDay = self::isAllDay($event);

        /*
         * An all-day occurrence is a date, not an instant. EventIterator hands it
         * over as midnight in the reference zone; converting that to UTC would
         * move it to 22:00 the day before and draw it on the wrong day. So the
         * date is taken as written and re-anchored at midnight UTC, which is the
         * convention `tasks.starts_at` already uses for all-day spans.
         */
        if ($allDay) {
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $start->format('Y-m-d').' 00:00:00', 'UTC');
            $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $end->format('Y-m-d').' 00:00:00', 'UTC');

            if ($endsAt <= $startsAt) {
                $endsAt = $startsAt->addDay();
            }
        } else {
            $startsAt = CarbonImmutable::instance($start)->utc();
            $endsAt = CarbonImmutable::instance($end)->utc();

            if ($endsAt <= $startsAt) {
                $endsAt = $startsAt->addMinutes((int) config('caldav.default_duration_min'));
            }
        }

        $title = trim((string) ($event->SUMMARY ?? ''));
        $location = trim((string) ($event->LOCATION ?? ''));
        $transparency = strtoupper(trim((string) ($event->TRANSP ?? '')));

        return new FeedOccurrence(
            feedId: (int) $feed->getKey(),
            uid: $uid,
            // Identifies the occurrence within its series, so two instances of
            // one weekly event get different ids on the grid.
            recurrenceId: $recurring ? $startsAt->format('Ymd\THis\Z') : null,
            title: $title === '' ? 'Busy' : $title,
            location: $location === '' ? null : $location,
            startsAt: $startsAt,
            endsAt: $endsAt,
            isAllDay: $allDay,
            isBusy: $transparency !== 'TRANSPARENT',
        );
    }

    private static function parse(?string $payload): ?VCalendar
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        try {
            $calendar = Reader::read($payload, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return null;
        }

        return $calendar instanceof VCalendar ? $calendar : null;
    }

    private static function briefly(Throwable $e): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');

        return $message === '' ? $e::class : mb_substr($message, 0, 160);
    }
}
