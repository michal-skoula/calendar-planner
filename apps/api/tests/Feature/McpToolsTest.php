<?php

namespace Tests\Feature;

use App\Mcp\Servers\PlannerServer;
use App\Mcp\Tools\CompleteTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ScheduleTaskTool;
use App\Mcp\Tools\UnscheduleTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Task;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

class McpToolsTest extends TestCase
{
    use ProvisionsCalendar, RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
        $this->tasks = app(TaskService::class);
    }

    public function test_all_eight_tools_are_registered(): void
    {
        PlannerServer::tools()->assertRegistered([
            ListTasksTool::class,
            GetTaskTool::class,
            CreateTaskTool::class,
            UpdateTaskTool::class,
            ScheduleTaskTool::class,
            UnscheduleTaskTool::class,
            CompleteTaskTool::class,
            DeleteTaskTool::class,
        ]);
    }

    public function test_the_tools_keep_their_documented_snake_case_names(): void
    {
        $expected = [
            ListTasksTool::class => 'list_tasks',
            GetTaskTool::class => 'get_task',
            CreateTaskTool::class => 'create_task',
            UpdateTaskTool::class => 'update_task',
            ScheduleTaskTool::class => 'schedule_task',
            UnscheduleTaskTool::class => 'unschedule_task',
            CompleteTaskTool::class => 'complete_task',
            DeleteTaskTool::class => 'delete_task',
        ];

        foreach ($expected as $class => $name) {
            $this->assertSame($name, app($class)->name(), "{$class} must be exposed as {$name}");
        }
    }

    public function test_create_task_puts_a_task_in_the_inbox(): void
    {
        PlannerServer::tool(CreateTaskTool::class, ['title' => 'Napsat Míle', 'duration_min' => 15])
            ->assertOk()
            ->assertSee('"status":"inbox"');

        $this->assertSame(0, $this->objectCount());
    }

    public function test_create_task_with_a_start_schedules_it(): void
    {
        PlannerServer::tool(CreateTaskTool::class, [
            'title' => 'Gym',
            'start' => '2026-10-26T18:00:00',
        ])->assertOk()->assertSee('"status":"scheduled"');

        // The start was given as local wall clock in Europe/Prague.
        $this->assertSame('2026-10-26 17:00:00', Task::sole()->starts_at->utc()->toDateTimeString());
    }

    public function test_schedule_task_on_an_already_scheduled_task_moves_it(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Move me']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );
        $uid = $task->ical_uid;

        PlannerServer::tool(ScheduleTaskTool::class, [
            'id' => $task->id,
            'start' => '2026-10-27T15:00:00',
            'duration_min' => 90,
        ])->assertOk();

        $task->refresh();

        $this->assertSame(1, $this->objectCount(), 'must not create a second event');
        $this->assertSame($uid, $task->ical_uid, 'the UID must be stable across a move');
        $this->assertSame('2026-10-27 14:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(90, (int) $task->starts_at->diffInMinutes($task->ends_at));
    }

    public function test_list_tasks_filters_by_status_and_by_tag(): void
    {
        $this->tasks->create(['title' => 'Inbox one', 'tags' => ['ops']]);
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'Scheduled one', 'tags' => ['health']]),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        PlannerServer::tool(ListTasksTool::class, ['status' => 'inbox'])
            ->assertOk()->assertSee('Inbox one')->assertDontSee('Scheduled one');

        PlannerServer::tool(ListTasksTool::class, ['tag' => 'health'])
            ->assertOk()->assertSee('Scheduled one')->assertDontSee('Inbox one');
    }

    public function test_list_tasks_filters_by_date_range(): void
    {
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'This week']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );
        $this->tasks->schedule(
            $this->tasks->create(['title' => 'Next month']),
            CarbonImmutable::parse('2026-11-26 09:00', 'Europe/Prague'),
        );

        PlannerServer::tool(ListTasksTool::class, [
            'from' => '2026-10-26T00:00:00',
            'to' => '2026-11-02T00:00:00',
        ])->assertOk()->assertSee('This week')->assertDontSee('Next month');
    }

    public function test_complete_then_unschedule_then_delete(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Lifecycle']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        PlannerServer::tool(CompleteTaskTool::class, ['id' => $task->id])->assertOk()->assertSee('"done":true');
        PlannerServer::tool(UnscheduleTaskTool::class, ['id' => $task->id])->assertOk();

        $this->assertSame(0, $this->objectCount());

        PlannerServer::tool(DeleteTaskTool::class, ['id' => $task->id])->assertOk()->assertSee('"deleted":true');

        $this->assertSame(0, Task::count());
    }

    public function test_update_task_changes_the_title_on_the_calendar_too(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Old name']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        PlannerServer::tool(UpdateTaskTool::class, ['id' => $task->id, 'title' => 'New name'])->assertOk();

        $this->assertStringContainsString(
            'SUMMARY:New name',
            $this->backend()->objectData($this->calendarId(), $task->refresh()->objectUri()),
        );
    }

    public function test_get_task_returns_local_wall_clock_without_an_offset(): void
    {
        $task = $this->tasks->schedule(
            $this->tasks->create(['title' => 'Zone check']),
            CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'),
        );

        PlannerServer::tool(GetTaskTool::class, ['id' => $task->id])
            ->assertOk()
            ->assertSee('"start":"2026-10-26T09:00:00"');
    }
}
