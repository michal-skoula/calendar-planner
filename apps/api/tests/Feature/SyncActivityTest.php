<?php

namespace Tests\Feature;

use App\Models\SyncClient;
use App\Models\SyncEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MatchesOpenApi;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * The sync endpoints answer two questions the container log cannot: is a device
 * still talking to this server, and what did it change.
 */
class SyncActivityTest extends TestCase
{
    use MatchesOpenApi, ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
    }

    public function test_a_dav_request_records_the_client_that_made_it(): void
    {
        $this->call('PROPFIND', '/dav', server: $this->davHeaders());

        $client = SyncClient::sole();
        $this->assertSame('Symfony', $client->agent);
        $this->assertSame(207, $client->last_status);
        $this->assertTrue($client->isHealthy());
        $this->assertSame(1, $client->request_count);
    }

    public function test_a_client_row_is_upserted_rather_than_appended(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->call('PROPFIND', '/dav', server: $this->davHeaders());
        }

        $this->assertSame(1, SyncClient::count());
        $this->assertSame(3, SyncClient::sole()->request_count);
    }

    public function test_an_event_put_by_a_device_is_recorded_as_coming_from_it(): void
    {
        $this->call(
            'PUT',
            $this->calendarHome('/tasks/from-phone.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']),
            content: $this->appleEvent('from-phone', ['SUMMARY' => 'Call the dentist']),
        );

        $event = SyncEvent::query()->where('kind', 'created')->sole();
        $this->assertSame(SyncEvent::SOURCE_CALDAV, $event->source);
        $this->assertSame('Symfony', $event->client);
        $this->assertSame('Call the dentist', $event->title);
        $this->assertStringContainsString('appeared from', $event->sentence());
    }

    public function test_deleting_an_event_records_a_return_to_the_inbox(): void
    {
        $this->call('PUT', $this->calendarHome('/tasks/bin-me.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']),
            content: $this->appleEvent('bin-me'));

        $this->call('DELETE', $this->calendarHome('/tasks/bin-me.ics'), server: $this->davHeaders());

        $event = SyncEvent::query()->where('kind', 'unscheduled')->sole();
        $this->assertSame(SyncEvent::SOURCE_CALDAV, $event->source);
        $this->assertStringContainsString('returned to the inbox', $event->sentence());
    }

    public function test_a_change_made_in_the_planner_is_not_attributed_to_a_device(): void
    {
        $task = app(TaskService::class)->create(['title' => 'Write the thing']);
        app(TaskService::class)->schedule($task, CarbonImmutable::parse('2026-10-29 09:00', 'Europe/Prague'), 30);

        $event = SyncEvent::query()->where('task_id', $task->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame(SyncEvent::SOURCE_PLANNER, $event->source);
        $this->assertNull($event->client);
        $this->assertStringContainsString('in the planner', $event->sentence());
    }

    public function test_a_refused_sign_in_is_recorded_as_a_failure(): void
    {
        $this->call('PROPFIND', '/dav', server: $this->davHeaders(auth: $this->basicAuth('wrong')));

        $event = SyncEvent::query()->where('kind', 'auth_refused')->sole();
        $this->assertTrue($event->isFailure());
        $this->assertStringContainsString('wrong password', (string) $event->detail);
        // The address attempted is useful; the password never is.
        $this->assertStringContainsString($this->calDavEmail, (string) $event->detail);
    }

    public function test_a_poll_that_changes_nothing_is_not_an_event(): void
    {
        $ics = $this->appleEvent('unchanged');

        $this->call('PUT', $this->calendarHome('/tasks/unchanged.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']), content: $ics);
        $created = SyncEvent::count();

        // The same payload again, as a device re-sending on a retry.
        $this->call('PUT', $this->calendarHome('/tasks/unchanged.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']), content: $ics);

        $this->assertSame($created, SyncEvent::count());
    }

    public function test_the_endpoints_list_clients_and_activity(): void
    {
        $this->call('PUT', $this->calendarHome('/tasks/on-page.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']),
            content: $this->appleEvent('on-page', ['SUMMARY' => 'Buy milk']));

        $this->actingAs($this->plannerUser());

        $this->getJson('/api/sync/clients')
            ->assertOk()
            ->assertJsonPath('data.0.agent', 'Symfony')
            ->assertJsonPath('data.0.healthy', true);

        $this->getJson('/api/sync/events')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Buy milk')
            ->assertJsonPath('data.0.source', SyncEvent::SOURCE_CALDAV)
            ->assertJsonPath('data.0.sentence', 'appeared from Symfony');
    }

    public function test_the_sync_endpoints_require_authentication(): void
    {
        $this->getJson('/api/sync/clients')->assertUnauthorized();
        $this->getJson('/api/sync/events')->assertUnauthorized();
    }

    public function test_a_refused_sign_in_is_listed_as_a_failure(): void
    {
        $this->call('PROPFIND', '/dav', server: $this->davHeaders(auth: $this->basicAuth('wrong')));

        $this->actingAs($this->plannerUser());

        $this->getJson('/api/sync/events')
            ->assertJsonPath('data.0.kind', 'auth_refused')
            ->assertJsonPath('data.0.failure', true);
    }

    public function test_recording_never_breaks_a_sync(): void
    {
        // The table going missing must cost a log line, not the write itself.
        Schema::drop('sync_events');

        $this->call('PUT', $this->calendarHome('/tasks/resilient.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']),
            content: $this->appleEvent('resilient', ['SUMMARY' => 'Still works']))
            ->assertStatus(201);

        $this->assertSame('Still works', Task::where('ical_uid', 'resilient')->sole()->title);
    }

    /**
     * @param  array<string, string>  $extra
     * @param  array<string, string>|null  $auth
     * @return array<string, string>
     */
    private function davHeaders(array $extra = [], ?array $auth = null): array
    {
        $server = [];
        foreach (($auth ?? $this->basicAuth()) + $extra + ['Depth' => '0'] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function plannerUser(): User
    {
        return User::sole();
    }
}
