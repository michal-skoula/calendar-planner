<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CompleteTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ScheduleTaskTool;
use App\Mcp\Tools\UnscheduleTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Planner')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
A single user's task planner. Tasks live in an inbox; scheduling one puts it on a
calendar that Apple Calendar reads and writes over CalDAV, so a change made here
reaches the user's phone and a change made on the phone is visible here.

Datetimes are interpreted and returned as naive local wall clock with no offset,
in the user's own timezone (for example 2026-10-26T09:00:00), so a value read from
one tool can be passed straight into another unchanged. `deadline` is a date only.

A task has three states. `inbox` means it has no calendar event. `scheduled` means
exactly one event carries it. `done` means it is finished; a finished task keeps
its slot on the calendar rather than disappearing.

`deadline` is when the work is due and is independent of `start`, which is when
the user intends to do it. Scheduling a task that is already scheduled moves it
rather than creating a second event. Recurring tasks are not supported.

Prefer complete_task over delete_task for work that actually got done.
TXT)]
class PlannerServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ListTasksTool::class,
        GetTaskTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        ScheduleTaskTool::class,
        UnscheduleTaskTool::class,
        CompleteTaskTool::class,
        DeleteTaskTool::class,
    ];

    /** @var array<int, class-string> */
    protected array $resources = [];

    /** @var array<int, class-string> */
    protected array $prompts = [];
}
