<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('complete_task')]
#[Description('Mark a task done. A scheduled task keeps its slot and its event summary gains a done marker.')]
class CompleteTaskTool extends PlannerTool
{
    public function handle(Request $request, TaskService $tasks): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        return Response::json($this->payload($tasks->complete($this->findTask((int) $data['id']))));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task id.')->required(),
        ];
    }
}
