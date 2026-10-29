<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_task')]
#[Description('Create a task. With a start it goes straight onto the calendar; without one it lands in the inbox.')]
class CreateTaskTool extends PlannerTool
{
    public function handle(Request $request, TaskService $tasks): Response
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'deadline' => ['nullable', 'date'],
            'duration_min' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'start' => ['nullable', 'date'],
        ]);

        $task = $tasks->create([
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? [],
            'deadline' => $data['deadline'] ?? null,
            'duration_min' => $data['duration_min'] ?? null,
            'start' => $data['start'] ?? null,
        ]);

        return Response::json($this->payload($task));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('What needs doing.')->required(),
            'notes' => $schema->string()->description('Longer detail, shown as the event description.'),
            'tags' => $schema->array()->items($schema->string())->description('Short labels.'),
            'deadline' => $schema->string()->description('Due date (YYYY-MM-DD), independent of when the work is scheduled.'),
            'duration_min' => $schema->integer()->description('Estimate in minutes; also the block length when scheduled.'),
            'start' => $schema->string()->description('Local datetime to schedule it at, e.g. 2026-10-26T09:00:00. Omit for the inbox.'),
        ];
    }
}
