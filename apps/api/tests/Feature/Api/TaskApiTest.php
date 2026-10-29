<?php

namespace Tests\Feature\Api;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MatchesOpenApi;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * The task endpoints, as the web app drives them: the inbox, dragging onto the
 * grid, moving, dragging back, completing. Every response is also checked
 * against openapi.yaml by MatchesOpenApi.
 */
class TaskApiTest extends TestCase
{
    use MatchesOpenApi, ProvisionsCalendar, RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
        $this->tasks = app(TaskService::class);
        $this->actingAs(User::sole());
    }

    public function test_the_api_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson('/api/tasks')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_the_inbox_lists_unscheduled_tasks_deadline_first(): void
    {
        $this->tasks->create(['title' => 'No deadline']);
        $this->tasks->create(['title' => 'Due later', 'deadline' => '2026-12-01']);
        $this->tasks->create(['title' => 'Due soon', 'deadline' => '2026-11-01']);
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'On the calendar']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        $this->getJson('/api/tasks?status=inbox')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Due soon', 'Due later', 'No deadline']);
    }

    public function test_a_task_says_how_long_a_drop_will_make_it(): void
    {
        $estimated = $this->tasks->create(['title' => 'Estimated', 'duration_min' => 90]);
        $bare = $this->tasks->create(['title' => 'Bare']);

        $this->getJson("/api/tasks/{$estimated->id}")->assertJsonPath('data.planned_duration_min', 90);
        $this->getJson("/api/tasks/{$bare->id}")
            ->assertJsonPath('data.duration_min', null)
            ->assertJsonPath('data.planned_duration_min', 30);
    }

    public function test_dragging_an_inbox_task_onto_the_grid_schedules_it(): void
    {
        $task = $this->tasks->create(['title' => 'Drag me', 'duration_min' => 45]);

        $this->postJson("/api/tasks/{$task->id}/schedule", [
            'start' => '2026-10-26T09:00:00',
            'duration_min' => 45,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Task::STATUS_SCHEDULED)
            ->assertJsonPath('data.start', '2026-10-26T09:00:00')
            ->assertJsonPath('data.end', '2026-10-26T09:45:00');

        $task->refresh();
        $this->assertSame('2026-10-26 08:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(1, $this->objectCount());
    }

    public function test_a_drop_with_no_minutes_uses_the_tasks_own_estimate(): void
    {
        $task = $this->tasks->create(['title' => 'Estimated', 'duration_min' => 90]);

        $this->postJson("/api/tasks/{$task->id}/schedule", ['start' => '2026-10-26T09:00:00'])->assertOk();

        $this->assertSame(90, (int) $task->refresh()->starts_at->diffInMinutes($task->ends_at));
    }

    public function test_moving_an_event_takes_its_length_from_the_new_end(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Move me']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        $this->postJson("/api/tasks/{$task->id}/schedule", [
            'start' => '2026-10-27T14:00:00',
            'end' => '2026-10-27T15:30:00',
        ])->assertOk();

        $task->refresh();
        $this->assertSame('2026-10-27 13:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(90, (int) $task->starts_at->diffInMinutes($task->ends_at));
        $this->assertSame(1, $this->objectCount(), 'a move rewrites the event rather than adding one');
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $task = $this->tasks->create(['title' => 'Backwards']);

        $this->postJson("/api/tasks/{$task->id}/schedule", [
            'start' => '2026-10-27T14:00:00',
            'end' => '2026-10-27T13:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('end');
    }

    public function test_dragging_an_event_back_to_the_inbox_unschedules_it(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Back to inbox', 'tags' => ['keep']]),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        $this->postJson("/api/tasks/{$task->id}/unschedule")
            ->assertOk()
            ->assertJsonPath('data.status', Task::STATUS_INBOX)
            ->assertJsonPath('data.start', null)
            ->assertJsonPath('data.tags', ['keep']);

        $this->assertSame(0, $this->objectCount());
    }

    public function test_a_calendar_window_returns_prague_wall_clock_on_both_sides_of_dst(): void
    {
        // 25 October 2026 is the night CET resumes, so these two blocks are an hour
        // apart in UTC but render at the same wall clock.
        $before = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Before DST']),
            CarbonImmutable::parse('2026-10-24 09:00', 'Europe/Prague'),
        );
        $after = $this->tasks->schedule(
            $this->tasks->create(['title' => 'After DST']),
            CarbonImmutable::parse('2026-10-27 09:00', 'Europe/Prague'),
        );

        $this->assertSame('2026-10-24 07:00:00', $before->starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-10-27 08:00:00', $after->starts_at->utc()->toDateTimeString());

        $byTitle = collect(
            $this->getJson('/api/tasks?from=2026-10-19T00:00:00&to=2026-11-02T00:00:00')->json('data')
        )->keyBy('title');

        $this->assertSame('2026-10-24T09:00:00', $byTitle['Before DST']['start']);
        $this->assertSame('2026-10-27T09:00:00', $byTitle['After DST']['start']);
    }

    public function test_a_calendar_window_only_returns_what_overlaps_it(): void
    {
        $this->tasks->create(['title' => 'In the inbox']);
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'In range']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'Out of range']),
            CarbonImmutable::parse('2026-12-26 09:00', 'Europe/Prague'),
        );

        $this->getJson('/api/tasks?from=2026-10-26T00:00:00&to=2026-11-02T00:00:00')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'In range');
    }

    public function test_an_all_day_event_from_a_device_comes_back_as_dates(): void
    {
        $this->backend()->createCalendarObject($this->calendarId(), 'all-day.ics', $this->appleEvent('all-day', [
            'DTSTART' => null,
            'DTEND' => null,
            'DTSTART;VALUE=DATE' => '20261027',
            'DTEND;VALUE=DATE' => '20261028',
        ]));

        $this->getJson('/api/tasks?from=2026-10-26T00:00:00&to=2026-11-02T00:00:00')
            ->assertJsonPath('data.0.all_day', true)
            ->assertJsonPath('data.0.start', '2026-10-27')
            ->assertJsonPath('data.0.end', '2026-10-28');
    }

    public function test_a_done_event_keeps_its_slot_and_its_plain_title(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Finished']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        $this->postJson("/api/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.done', true)
            ->assertJsonPath('data.start', '2026-10-26T09:00:00')
            ->assertJsonPath('data.title', 'Finished');
    }

    public function test_completing_and_reopening_from_the_inbox(): void
    {
        $task = $this->tasks->create(['title' => 'Tick me']);

        $this->postJson("/api/tasks/{$task->id}/complete")->assertJsonPath('data.done', true);
        $this->assertTrue($task->refresh()->isDone());

        $this->postJson("/api/tasks/{$task->id}/reopen")->assertJsonPath('data.done', false);
        $this->assertFalse($task->refresh()->isDone());
    }

    public function test_done_today_lists_what_was_completed_since_midnight(): void
    {
        CarbonImmutable::setTestNow('2026-10-27 15:00:00');

        $this->tasks->complete($this->tasks->create(['title' => 'Today']));
        $this->tasks->create(['title' => 'Still open']);

        $this->getJson('/api/tasks?completed_after=2026-10-27T00:00:00')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Today']);

        CarbonImmutable::setTestNow();
    }

    public function test_adding_a_task_from_the_inbox_form(): void
    {
        $this->postJson('/api/tasks', ['title' => 'Typed in the sidebar', 'duration_min' => 25])
            ->assertCreated()
            ->assertJsonPath('data.status', Task::STATUS_INBOX);

        $task = Task::sole();
        $this->assertSame('Typed in the sidebar', $task->title);
        $this->assertSame(25, $task->duration_min);
    }

    public function test_an_empty_title_is_rejected(): void
    {
        $this->postJson('/api/tasks', ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->assertSame(0, Task::count());
    }

    public function test_editing_a_scheduled_task_reaches_the_event(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Old name']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        $this->patchJson("/api/tasks/{$task->id}", ['title' => 'New name', 'deadline' => '2026-11-01'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New name')
            ->assertJsonPath('data.deadline', '2026-11-01')
            ->assertJsonPath('data.start', '2026-10-26T09:00:00');

        $this->assertStringContainsString('SUMMARY:New name', $this->backend()->objectData($this->calendarId(), $task->refresh()->objectUri()));
    }

    public function test_deleting_a_task_takes_its_event_with_it(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Gone']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

        $this->assertSame(0, Task::count());
        $this->assertSame(0, $this->objectCount());
    }

    public function test_an_unknown_task_is_a_json_404(): void
    {
        $this->getJson('/api/tasks/999')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_the_revision_only_changes_when_something_did(): void
    {
        $first = $this->getJson('/api/revision')->assertOk()->json('data.revision');

        $this->assertSame($first, $this->getJson('/api/revision')->json('data.revision'));

        // Something arrives over CalDAV while the page is open.
        $this->backend()->createCalendarObject($this->calendarId(), 'from-phone.ics', $this->appleEvent('from-phone'));

        $this->assertNotSame($first, $this->getJson('/api/revision')->json('data.revision'));
    }

    public function test_me_reports_the_timezone_clients_draw_in(): void
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $this->calDavEmail)
            ->assertJsonPath('data.timezone', 'Europe/Prague')
            ->assertJsonPath('data.default_duration_min', 30);
    }
}
