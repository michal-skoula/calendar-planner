<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_task')]
#[Description('Change a task\'s title, notes, tags, deadline or estimate. Use schedule_task to change when it happens.')]
class UpdateTaskTool extends PlannerTool
{
    public function handle(Request $request, TaskService $tasks): Response
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:40'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'duration_min' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $task = $this->findTask((int) $data['id']);

        unset($data['id']);

        return Response::json($this->payload($tasks->updateDetails($task, $data)));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task id.')->required(),
            'title' => $schema->string(),
            'notes' => $schema->string()->nullable(),
            'tags' => $schema->array()->items($schema->string()),
            'deadline' => $schema->string()->nullable()->description('YYYY-MM-DD, or null to clear.'),
            'duration_min' => $schema->integer()->nullable(),
        ];
    }
}
