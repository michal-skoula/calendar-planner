<?php

namespace Tests\Feature;

use App\Livewire\Planner;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

class PlannerComponentTest extends TestCase
{
    use ProvisionsCalendar, RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
        $this->tasks = app(TaskService::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_planner_requires_a_session_login(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_planner_page_renders(): void
    {
        $this->tasks->create(['title' => 'Visible in the inbox']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Inbox')
            ->assertSee('Visible in the inbox');
    }

    public function test_the_login_page_renders(): void
    {
        auth()->logout();

        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_dragging_an_inbox_task_onto_the_grid_schedules_it(): void
    {
        $task = $this->tasks->create(['title' => 'Drag me', 'duration_min' => 45]);

        Livewire::test(Planner::class)
            ->call('scheduleFromInbox', $task->id, '2026-10-26T09:00:00', 45);

        $task->refresh();

        $this->assertSame(Task::STATUS_SCHEDULED, $task->status);
        $this->assertSame('2026-10-26 08:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(45, (int) $task->starts_at->diffInMinutes($task->ends_at));
    }

    public function test_a_drop_with_no_minutes_uses_the_tasks_own_estimate(): void
    {
        $task = $this->tasks->create(['title' => 'Estimated', 'duration_min' => 90]);

        Livewire::test(Planner::class)->call('scheduleFromInbox', $task->id, '2026-10-26T09:00:00', null);

        $this->assertSame(90, (int) $task->refresh()->starts_at->diffInMinutes($task->ends_at));
    }

    public function test_dragging_an_event_reports_its_new_span(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Move me']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        Livewire::test(Planner::class)
            ->call('moveEvent', $task->id, '2026-10-27T14:00:00', '2026-10-27T15:30:00');

        $task->refresh();

        $this->assertSame('2026-10-27 13:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(90, (int) $task->starts_at->diffInMinutes($task->ends_at));
    }

    public function test_dragging_an_event_back_to_the_inbox_unschedules_it(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Back to inbox']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        Livewire::test(Planner::class)
            ->call('unscheduleEvent', $task->id)
            ->assertDispatched('calendar-stale');

        $this->assertSame(Task::STATUS_INBOX, $task->refresh()->status);
        $this->assertSame(0, $this->objectCount());
    }

    public function test_the_events_feed_returns_prague_wall_clock_on_both_sides_of_dst(): void
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

        $events = Livewire::test(Planner::class)
            ->call('events', '2026-10-19T00:00:00', '2026-11-02T00:00:00')
            ->effects['returns'][0];

        $byTitle = collect($events)->keyBy('title');

        $this->assertSame('2026-10-24T09:00:00', $byTitle['Before DST']['start']);
        $this->assertSame('2026-10-27T09:00:00', $byTitle['After DST']['start']);
    }

    public function test_the_events_feed_only_returns_the_visible_range(): void
    {
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'In range']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'Out of range']),
            CarbonImmutable::parse('2026-12-26 09:00', 'Europe/Prague'),
        );

        $events = Livewire::test(Planner::class)
            ->call('events', '2026-10-26T00:00:00', '2026-11-02T00:00:00')
            ->effects['returns'][0];

        $this->assertCount(1, $events);
        $this->assertSame('In range', $events[0]['title']);
    }

    public function test_a_done_event_is_marked_for_the_grid(): void
    {
        $task = $this->tasks->complete($this->tasks->schedule(
            $this->tasks->create(['title' => 'Finished']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        ));

        $events = Livewire::test(Planner::class)
            ->call('events', '2026-10-26T00:00:00', '2026-11-02T00:00:00')
            ->effects['returns'][0];

        $this->assertContains('planner-done', $events[0]['classNames']);
        $this->assertTrue($events[0]['extendedProps']['done']);
        $this->assertSame('Finished', $events[0]['title'], 'the done marker belongs in the event summary, not the title');
        $this->assertNotNull($task->completed_at);
    }

    public function test_adding_a_task_from_the_inbox_form(): void
    {
        Livewire::test(Planner::class)
            ->set('newTitle', 'Typed in the sidebar')
            ->set('newDuration', 25)
            ->call('addTask')
            ->assertHasNoErrors()
            ->assertSet('newTitle', '');

        $task = Task::sole();
        $this->assertSame('Typed in the sidebar', $task->title);
        $this->assertSame(25, $task->duration_min);
        $this->assertSame(Task::STATUS_INBOX, $task->status);
    }

    public function test_an_empty_title_is_rejected(): void
    {
        Livewire::test(Planner::class)->call('addTask')->assertHasErrors('newTitle');

        $this->assertSame(0, Task::count());
    }

    public function test_the_heartbeat_only_fires_when_something_changed(): void
    {
        $component = Livewire::test(Planner::class);

        $component->call('heartbeat')->assertNotDispatched('calendar-stale');

        // Something arrives over CalDAV while the page is open.
        $this->backend()->createCalendarObject($this->calendarId(), 'from-phone.ics', $this->appleEvent('from-phone'));

        $component->call('heartbeat')->assertDispatched('calendar-stale');
    }

    public function test_toggling_completion_from_the_inbox(): void
    {
        $task = $this->tasks->create(['title' => 'Tick me']);

        Livewire::test(Planner::class)->call('toggleComplete', $task->id);
        $this->assertTrue($task->refresh()->isDone());

        Livewire::test(Planner::class)->call('toggleComplete', $task->id);
        $this->assertFalse($task->refresh()->isDone());
    }
}
