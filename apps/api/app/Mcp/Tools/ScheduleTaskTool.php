<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('schedule_task')]
#[Description('Put a task on the calendar at a given local time, or move it if it is already there. Never creates a second event for the same task.')]
class ScheduleTaskTool extends PlannerTool
{
    public function handle(Request $request, TaskService $tasks): Response
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'start' => ['required', 'date'],
            'duration_min' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $task = $tasks->schedule(
            $this->findTask((int) $data['id']),
            CarbonImmutable::parse($data['start'], self::zone()),
            isset($data['duration_min']) ? (int) $data['duration_min'] : null,
        );

        return Response::json($this->payload($task));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task id.')->required(),
            'start' => $schema->string()
                ->description('Local datetime with no offset, e.g. 2026-10-26T09:00:00.')
                ->required(),
            'duration_min' => $schema->integer()
                ->description('Block length. Defaults to the task\'s own estimate, then to the configured default.'),
        ];
    }
}
