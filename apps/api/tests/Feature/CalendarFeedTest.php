<?php

namespace Tests\Feature;

use App\Feeds\FeedEvents;
use App\Feeds\FeedFetcher;
use App\Feeds\FeedFetchResult;
use App\Feeds\FeedGuard;
use App\Models\CalendarFeed;
use App\Models\SyncEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MatchesOpenApi;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use MatchesOpenApi, ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
        $this->actingAs(User::query()->first());

        config(['feeds.allow_private' => false]);

        // The real guard with fake DNS: the range checks still run, so a test
        // that expects a refusal gets one for the right reason.
        $this->app->instance(FeedGuard::class, new FeedGuard(
            fn (string $host): array => ['93.184.216.34'],
        ));
    }

    // --- fetching ---------------------------------------------------------

    public function test_a_calendar_that_parses_is_stored_and_its_series_counted(): void
    {
        Http::fake(['*' => Http::response($this->fixture('weekly-override'), 200, ['ETag' => '"v1"'])]);

        $feed = $this->feed();
        $result = app(FeedFetcher::class)->fetch($feed);

        $this->assertSame(FeedFetchResult::Changed, $result);

        $feed->refresh();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $feed->payload);
        $this->assertSame('"v1"', $feed->etag);
        $this->assertSame(1, $feed->series_count);
        $this->assertSame(0, $feed->dropped_count);
        $this->assertNull($feed->last_error);
        $this->assertNotNull($feed->last_success_at);
    }

    public function test_the_second_fetch_asks_the_server_whether_anything_changed(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->fixture('weekly-override'), 200, ['ETag' => '"v1"', 'Last-Modified' => 'Mon, 26 Oct 2026 10:00:00 GMT'])
                ->push('', 304),
        ]);

        $feed = $this->feed();
        $fetcher = app(FeedFetcher::class);

        $fetcher->fetch($feed);
        $stored = $feed->refresh()->payload;

        $this->assertSame(FeedFetchResult::Unchanged, $fetcher->fetch($feed));

        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1"')
            && $request->hasHeader('If-Modified-Since', 'Mon, 26 Oct 2026 10:00:00 GMT'));

        $this->assertSame($stored, $feed->refresh()->payload, 'a 304 must not touch the payload');
    }

    public function test_an_identical_body_is_treated_as_unchanged(): void
    {
        $body = $this->fixture('weekly-override');

        // A provider that sends no ETag re-sends the whole document every time.
        Http::fake(['*' => Http::sequence()->push($body, 200)->push($body, 200)]);

        $feed = $this->feed();
        $fetcher = app(FeedFetcher::class);

        $this->assertSame(FeedFetchResult::Changed, $fetcher->fetch($feed));
        $this->assertSame(FeedFetchResult::Unchanged, $fetcher->fetch($feed));
        $this->assertSame(1, $feed->refresh()->series_count);
    }

    public function test_a_server_error_leaves_the_last_good_calendar_in_place(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->fixture('weekly-override'), 200)
                ->push('upstream is unwell', 503),
        ]);

        $feed = $this->feed();
        $fetcher = app(FeedFetcher::class);

        $fetcher->fetch($feed);
        $good = $feed->refresh()->payload;

        $this->assertSame(FeedFetchResult::Failed, $fetcher->fetch($feed));

        $feed->refresh();
        $this->assertSame($good, $feed->payload, 'a failure must not blank the calendar');
        $this->assertSame(503, $feed->last_status);
        $this->assertStringContainsString('503', (string) $feed->last_error);

        // Fresh data, but the last attempt errored, so the dot goes red now
        // rather than an hour from now.
        $this->assertFalse($feed->isHealthy());
        $this->assertStringContainsString('last attempt failed', $feed->statusLine());

        $this->assertDatabaseHas('sync_events', [
            'kind' => 'feed_failed',
            'source' => SyncEvent::SOURCE_FEED,
        ]);
    }

    /**
     * A provider whose link has expired answers 200 with a sign-in page, which
     * looks like success at the HTTP layer and would otherwise blank the grid.
     */
    public function test_an_html_sign_in_page_is_not_mistaken_for_a_calendar(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->fixture('weekly-override'), 200)
                ->push(file_get_contents(base_path('tests/Fixtures/feeds/login-page.html')), 200, ['Content-Type' => 'text/html']),
        ]);

        $feed = $this->feed();
        $fetcher = app(FeedFetcher::class);

        $fetcher->fetch($feed);
        $good = $feed->refresh()->payload;

        $this->assertSame(FeedFetchResult::Failed, $fetcher->fetch($feed));

        $feed->refresh();
        $this->assertSame($good, $feed->payload);
        $this->assertStringContainsString('did not answer with a calendar', (string) $feed->last_error);
    }

    public function test_a_body_over_the_cap_is_refused_with_the_previous_one_intact(): void
    {
        config(['feeds.max_bytes' => 512]);

        Http::fake([
            '*' => Http::sequence()
                ->push($this->fixture('weekly-exdate'), 200)
                ->push('BEGIN:VCALENDAR'.str_repeat('x', 4096), 200),
        ]);

        $feed = $this->feed();
        $fetcher = app(FeedFetcher::class);

        $fetcher->fetch($feed);
        $good = $feed->refresh()->payload;

        $this->assertSame(FeedFetchResult::Failed, $fetcher->fetch($feed));

        $feed->refresh();
        $this->assertSame($good, $feed->payload);
        $this->assertStringContainsString('larger than', (string) $feed->last_error);
    }

    public function test_a_url_on_this_network_is_refused_before_anything_is_requested(): void
    {
        Http::fake();

        $this->app->instance(FeedGuard::class, new FeedGuard(
            fn (string $host): array => ['169.254.169.254'],
        ));

        $feed = $this->feed();

        $this->assertSame(FeedFetchResult::Failed, app(FeedFetcher::class)->fetch($feed));

        Http::assertNothingSent();
        $this->assertStringContainsString('169.254', (string) $feed->refresh()->last_error);
    }

    public function test_a_series_the_expander_cannot_read_is_reported_when_the_feed_arrives(): void
    {
        // The probe window is relative to now, so the fixture's own dates have
        // to be in it for the broken rule to be reached.
        CarbonImmutable::setTestNow('2026-11-02 09:00:00');

        Http::fake(['*' => Http::response($this->fixture('one-broken-series'), 200)]);

        $feed = $this->feed();
        app(FeedFetcher::class)->fetch($feed);

        $feed->refresh();
        $this->assertSame(2, $feed->series_count);
        $this->assertSame(1, $feed->dropped_count);

        $this->assertDatabaseHas('sync_events', [
            'kind' => 'feed_series_dropped',
            'source' => SyncEvent::SOURCE_FEED,
        ]);

        CarbonImmutable::setTestNow();
    }

    // --- the read path ----------------------------------------------------

    public function test_rendering_a_week_opens_no_socket(): void
    {
        Http::fake();

        $this->storedFeed('daily-since-2015');

        $events = app(FeedEvents::class)->forWindow(
            CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-09 00:00:00', 'UTC'),
        );

        $this->assertNotEmpty($events);
        Http::assertNothingSent();
    }

    public function test_feed_events_reach_clients_as_read_only_occurrences(): void
    {
        $this->storedFeed('weekly-override');

        $events = $this->getJson('/api/feed-events?from=2026-11-02T00:00:00&to=2026-11-09T00:00:00')
            ->assertOk()
            ->json('data');

        // Two: the moved 3 November instance, and the 9 November one that falls
        // inside the window's padding. A client draws only what it asked for;
        // the padding is there so an event starting just outside is not missed.
        $this->assertCount(2, $events);

        $event = $events[0];
        $this->assertSame('One to one (moved)', $event['title']);
        $this->assertSame('#4f6d9a', $event['color']);
        $this->assertSame('Example', $event['feed_name']);
        // Wall clock in the display timezone, the same convention the task
        // endpoints use. 13:00Z is 14:00 in Prague in November.
        $this->assertSame('2026-11-03T14:00:00', $event['start']);
    }

    public function test_no_feed_event_id_can_be_mistaken_for_a_task(): void
    {
        $task = app(TaskService::class)->create(['title' => 'Real work']);
        $this->storedFeed('daily-since-2015');

        $events = app(FeedEvents::class)->forWindow(
            CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-09 00:00:00', 'UTC'),
        );

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertNotSame((string) $task->id, $event['id']);
            $this->assertFalse(ctype_digit($event['id']));

            // Tasks and feed events share a grid. "feed-1-…" casts to 0 in
            // JavaScript's Number(), which is no task at all.
            $this->assertNull(Task::query()->find((int) $event['id']));
        }
    }

    public function test_hiding_a_feed_removes_its_events_without_discarding_its_payload(): void
    {
        $feed = $this->storedFeed('daily-since-2015');

        $this->patchJson("/api/feeds/{$feed->id}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->getJson('/api/feed-events?from=2026-11-02T00:00:00&to=2026-11-09T00:00:00')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($feed->refresh()->payload);
    }

    public function test_a_refreshed_payload_changes_the_revision(): void
    {
        $feed = $this->storedFeed('daily-since-2015');

        $before = $this->getJson('/api/revision')->json('data.revision');

        // Written in the same second as the row above, which is exactly the
        // case an updated_at comparison would miss.
        $feed->payload = $this->fixture('weekly-exdate');
        $feed->payload_hash = hash('sha256', $feed->payload);
        $feed->save();

        $this->assertNotSame($before, $this->getJson('/api/revision')->json('data.revision'));
    }

    // --- the endpoints ----------------------------------------------------

    public function test_subscribing_fetches_the_calendar_straight_away(): void
    {
        Http::fake(['*' => Http::response($this->fixture('weekly-exdate'), 200)]);

        $this->postJson('/api/feeds', [
            'url' => 'webcal://Example.com/shared/cal.ics',
            'name' => 'Work',
            'color' => '#4F6D9A',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Work')
            ->assertJsonPath('data.color', '#4f6d9a')
            ->assertJsonPath('data.healthy', true)
            ->assertJsonMissingPath('data.url');

        $feed = CalendarFeed::query()->sole();
        $this->assertSame('https://example.com/shared/cal.ics', $feed->url);
        $this->assertNotNull($feed->payload);
    }

    public function test_a_failed_first_fetch_still_subscribes_and_says_why(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);

        $this->postJson('/api/feeds', ['url' => 'https://example.com/cal.ics', 'color' => '#4f6d9a'])
            ->assertCreated()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.last_status', 503);
    }

    public function test_the_same_calendar_cannot_be_subscribed_to_twice(): void
    {
        Http::fake(['*' => Http::response($this->fixture('weekly-exdate'), 200)]);

        $this->postJson('/api/feeds', ['url' => 'https://example.com/cal.ics', 'color' => '#4f6d9a'])->assertCreated();
        $this->postJson('/api/feeds', ['url' => 'webcal://Example.com/cal.ics#again', 'color' => '#4f6d9a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame(1, CalendarFeed::query()->count());
    }

    public function test_listing_and_renaming_a_feed(): void
    {
        $this->getJson('/api/feeds')->assertOk()->assertJsonCount(0, 'data');

        $feed = $this->storedFeed('weekly-exdate');

        $this->getJson('/api/feeds')->assertOk()->assertJsonPath('data.0.name', 'Example');

        $this->patchJson("/api/feeds/{$feed->id}", ['name' => ' Shared work ', 'color' => '#AA3311'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Shared work');

        $this->assertSame('Shared work', $feed->refresh()->name);
        $this->assertSame('#aa3311', $feed->color);
    }

    public function test_a_bad_colour_is_refused(): void
    {
        $feed = $this->storedFeed('weekly-exdate');

        $this->patchJson("/api/feeds/{$feed->id}", ['color' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('color');
    }

    public function test_renaming_a_feed_changes_the_revision(): void
    {
        $feed = $this->storedFeed('weekly-exdate');

        $before = $this->getJson('/api/revision')->json('data.revision');

        $this->patchJson("/api/feeds/{$feed->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertNotSame($before, $this->getJson('/api/revision')->json('data.revision'));
    }

    public function test_a_failing_feed_is_reported_unhealthy(): void
    {
        $feed = $this->storedFeed('weekly-exdate');

        $this->getJson('/api/feeds')->assertJsonPath('data.0.healthy', true);

        $feed->last_error = 'answered 503';
        $feed->save();

        $this->getJson('/api/feeds')
            ->assertJsonPath('data.0.healthy', false)
            ->assertJsonPath('data.0.last_error', 'answered 503');
    }

    public function test_refreshing_a_feed_fetches_it_now(): void
    {
        Http::fake(['*' => Http::response($this->fixture('weekly-override'), 200)]);

        $feed = $this->feed();

        $this->postJson("/api/feeds/{$feed->id}/refresh")
            ->assertOk()
            ->assertJsonPath('data.series_count', 1);

        Http::assertSentCount(1);
    }

    public function test_removing_a_feed_takes_its_events_with_it(): void
    {
        $feed = $this->storedFeed('daily-since-2015');

        $this->deleteJson("/api/feeds/{$feed->id}")->assertNoContent();

        $this->assertSame(0, CalendarFeed::query()->count());
        $this->assertSame([], app(FeedEvents::class)->forWindow(
            CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-09 00:00:00', 'UTC'),
        ));
    }

    public function test_a_url_this_server_will_not_fetch_is_refused_at_subscription(): void
    {
        Http::fake();

        $this->app->instance(FeedGuard::class, new FeedGuard(
            fn (string $host): array => ['127.0.0.1'],
        ));

        $this->postJson('/api/feeds', ['url' => 'https://planner.internal/cal.ics', 'color' => '#4f6d9a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, CalendarFeed::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_url_is_not_readable_in_the_database(): void
    {
        $feed = $this->feed();

        $raw = DB::table('calendar_feeds')
            ->where('id', $feed->id)->value('url');

        $this->assertStringNotContainsString('example.com', $raw);
        $this->assertSame('https://example.com/cal.ics', $feed->fresh()->url);
    }

    public function test_the_refresh_command_skips_feeds_that_are_not_due(): void
    {
        Http::fake(['*' => Http::response($this->fixture('weekly-exdate'), 200)]);

        $feed = $this->storedFeed('weekly-exdate');
        $feed->last_success_at = CarbonImmutable::now();
        $feed->refresh_min = 60;
        $feed->save();

        $this->artisan('planner:feeds:refresh')->expectsOutputToContain('Nothing was due.')->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('planner:feeds:refresh', ['--force' => true])->assertSuccessful();
        Http::assertSentCount(1);
    }

    // --- helpers ----------------------------------------------------------

    private function feed(string $url = 'https://example.com/cal.ics'): CalendarFeed
    {
        return CalendarFeed::query()->create([
            'name' => 'Example',
            'url' => $url,
            'url_hash' => CalendarFeed::hashFor($url),
            'color' => '#4f6d9a',
            'refresh_min' => 60,
        ]);
    }

    /** A feed already holding a fixture, with no fetch involved. */
    private function storedFeed(string $fixture): CalendarFeed
    {
        $feed = $this->feed();
        $feed->payload = $this->fixture($fixture);
        $feed->payload_hash = hash('sha256', $feed->payload);
        $feed->payload_bytes = strlen($feed->payload);
        $feed->last_success_at = CarbonImmutable::now();
        $feed->last_status = 200;
        $feed->save();

        return $feed;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/feeds/{$name}.ics"));
    }
}
