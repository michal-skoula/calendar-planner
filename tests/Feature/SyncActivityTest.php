<?php

namespace Tests\Feature;

use App\Livewire\Planner;
use App\Livewire\SyncActivity;
use App\Models\SyncClient;
use App\Models\SyncEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * The sync page answers two questions the container log cannot: is a device
 * still talking to this server, and what did it change.
 */
class SyncActivityTest extends TestCase
{
    use ProvisionsCalendar, RefreshDatabase;

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

    public function test_the_page_lists_clients_and_activity(): void
    {
        $this->call('PUT', $this->calendarHome('/tasks/on-page.ics'),
            server: $this->davHeaders(['Content-Type' => 'text/calendar; charset=utf-8']),
            content: $this->appleEvent('on-page', ['SUMMARY' => 'Buy milk']));

        Livewire::actingAs($this->plannerUser())
            ->test(SyncActivity::class)
            ->assertOk()
            ->assertSee('Symfony')
            ->assertSee('Buy milk')
            ->assertSee('appeared from');
    }

    public function test_the_sync_page_requires_a_session_login(): void
    {
        $this->get('/sync')->assertRedirect('/login');
    }

    public function test_the_planner_header_shows_when_a_client_last_checked_in(): void
    {
        $this->call('PROPFIND', '/dav', server: $this->davHeaders());

        Livewire::actingAs($this->plannerUser())
            ->test(Planner::class)
            ->assertOk()
            ->assertSee(route('sync'), escape: false);
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
