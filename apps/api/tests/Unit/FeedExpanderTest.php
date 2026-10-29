<?php

namespace Tests\Unit;

use App\Feeds\FeedExpander;
use App\Feeds\FeedExpansion;
use App\Feeds\FeedOccurrence;
use App\Models\CalendarFeed;
use Carbon\CarbonImmutable;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Settings;
use Tests\TestCase;

/**
 * The core of subscriptions: turning someone else's recurrence rules into
 * rectangles, and surviving the ones that cannot be turned into anything.
 *
 * The window is fixed rather than relative to today, so the fixtures can name
 * real dates and the assertions can say which ones they expect.
 */
class FeedExpanderTest extends TestCase
{
    private FeedExpander $expander;

    /** Monday 2 November 2026 to Monday 16 November, two weeks. */
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'caldav.display_timezone' => 'Europe/Prague',
            'caldav.default_duration_min' => 30,
        ]);

        $this->expander = app(FeedExpander::class);
        $this->from = CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC');
        $this->to = CarbonImmutable::parse('2026-11-16 00:00:00', 'UTC');
    }

    /**
     * The headline case. Sabre's own expander throws on this fixture, which the
     * assertion below states outright so nobody switches back to it by accident.
     */
    public function test_a_daily_series_that_started_in_2015_still_renders(): void
    {
        $result = $this->expand('daily-since-2015');

        $this->assertCount(14, $result->occurrences);
        $this->assertSame([], $result->dropped);
        $this->assertSame('Standup', $result->occurrences[0]->title);
        $this->assertSame('2026-11-02T08:00:00+00:00', $result->occurrences[0]->startsAt->toIso8601String());
    }

    public function test_sabres_own_expander_cannot_do_that(): void
    {
        $calendar = Reader::read(self::fixture('daily-since-2015'), Reader::OPTION_FORGIVING);

        $this->expectException(MaxInstancesExceededException::class);

        $calendar->expand($this->from->toDateTimeImmutable(), $this->to->toDateTimeImmutable());
    }

    public function test_an_excluded_occurrence_does_not_appear(): void
    {
        $result = $this->expand('weekly-exdate');

        $this->assertCount(1, $result->occurrences);
        $this->assertSame(
            '2026-11-09T09:00:00+00:00',
            $result->occurrences[0]->startsAt->toIso8601String(),
            'the 2 November instance is on an EXDATE and should be gone',
        );
    }

    public function test_a_moved_occurrence_appears_once_at_its_new_time(): void
    {
        $result = $this->expand('weekly-override');

        $this->assertCount(2, $result->occurrences);

        [$moved, $normal] = $result->occurrences;

        $this->assertSame('One to one (moved)', $moved->title);
        $this->assertSame('2026-11-03T13:00:00+00:00', $moved->startsAt->toIso8601String());

        $this->assertSame('One to one', $normal->title);
        $this->assertSame('2026-11-09T09:00:00+00:00', $normal->startsAt->toIso8601String());

        $starts = array_map(
            fn (FeedOccurrence $o): string => $o->startsAt->toDateTimeString(),
            $result->occurrences,
        );
        $this->assertNotContains('2026-11-02 09:00:00', $starts, 'the original slot should be vacated');
    }

    public function test_one_unreadable_series_drops_and_the_rest_of_the_feed_renders(): void
    {
        $result = $this->expand('one-broken-series');

        $this->assertCount(1, $result->occurrences);
        $this->assertSame('Lunch', $result->occurrences[0]->title);

        $this->assertArrayHasKey('unreadable-rule', $result->dropped);
        $this->assertStringContainsString('FORTNIGHTLY', $result->dropped['unreadable-rule']);
        $this->assertSame(2, $result->seriesSeen);
    }

    public function test_a_series_that_outruns_the_budget_drops_rather_than_hangs(): void
    {
        $result = $this->expand('runaway-series');

        $this->assertCount(1, $result->occurrences);
        $this->assertSame('Still drawn', $result->occurrences[0]->title);
        $this->assertArrayHasKey('daily-since-1960', $result->dropped);
    }

    /**
     * Settings::$maxRecurrences is process-wide and FrankenPHP keeps the worker,
     * so a leak here would mean later DAV writes running with no recurrence
     * limit at all.
     */
    public function test_the_global_recurrence_limit_is_restored_after_a_series_throws(): void
    {
        $before = Settings::$maxRecurrences;
        $this->assertSame(3500, $before);

        $this->expand('one-broken-series');

        $this->assertSame($before, Settings::$maxRecurrences);
    }

    public function test_a_floating_start_lands_at_that_wall_clock_in_prague(): void
    {
        $result = $this->expand('floating-and-all-day');

        $floating = $this->titled($result->occurrences, 'No zone at all');

        // 09:00 with no zone, read as Prague, which is UTC+1 in November.
        $this->assertSame('2026-11-03T08:00:00+00:00', $floating->startsAt->toIso8601String());
        $this->assertFalse($floating->isAllDay);
    }

    public function test_an_all_day_span_keeps_its_dates_and_an_exclusive_end(): void
    {
        $result = $this->expand('floating-and-all-day');

        $allDay = $this->titled($result->occurrences, 'Off in Brno');

        $this->assertTrue($allDay->isAllDay);
        $this->assertSame('2026-11-04', $allDay->startsAt->toDateString());
        $this->assertSame('2026-11-06', $allDay->endsAt->toDateString(), 'DTEND stays exclusive');
    }

    public function test_a_cancelled_event_is_not_drawn(): void
    {
        $result = $this->expand('floating-and-all-day');

        $titles = array_map(fn (FeedOccurrence $o): string => $o->title, $result->occurrences);

        $this->assertNotContains('Cancelled, still in the feed', $titles);
        $this->assertCount(2, $result->occurrences);
    }

    public function test_occurrence_ids_are_never_bare_integers(): void
    {
        $result = $this->expand('daily-since-2015');

        foreach ($result->occurrences as $occurrence) {
            // resources/js/app.js reads Number(event.id) and completes the task
            // it names, so a numeric id here would mark off an unrelated task.
            $this->assertFalse(
                ctype_digit($occurrence->eventId()),
                "{$occurrence->eventId()} would collide with a task id",
            );
            $this->assertStringStartsWith('feed-', $occurrence->eventId());
        }

        $ids = array_map(fn (FeedOccurrence $o): string => $o->eventId(), $result->occurrences);
        $this->assertCount(14, array_unique($ids), 'each occurrence of a series needs its own id');
    }

    public function test_an_empty_payload_expands_to_nothing(): void
    {
        $feed = $this->feed('');

        $result = $this->expander->expand($feed, $this->from, $this->to);

        $this->assertSame([], $result->occurrences);
        $this->assertSame(0, $result->seriesSeen);
    }

    private function expand(string $fixture): FeedExpansion
    {
        return $this->expander->expand($this->feed(self::fixture($fixture)), $this->from, $this->to);
    }

    private function feed(string $payload): CalendarFeed
    {
        $feed = new CalendarFeed(['name' => 'Fixture', 'color' => '#4f6d9a']);
        $feed->id = 1;
        $feed->payload = $payload;

        return $feed;
    }

    /** @param array<int, FeedOccurrence> $occurrences */
    private function titled(array $occurrences, string $title): FeedOccurrence
    {
        foreach ($occurrences as $occurrence) {
            if ($occurrence->title === $title) {
                return $occurrence;
            }
        }

        $this->fail("No occurrence titled \"{$title}\".");
    }

    private static function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/feeds/{$name}.ics"));
    }
}
