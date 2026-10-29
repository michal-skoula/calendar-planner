<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_task')]
#[Description('Delete a task outright, removing its calendar event too. Prefer complete_task for finished work.')]
class DeleteTaskTool extends PlannerTool
{
    public function handle(Request $request, TaskService $tasks): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        $tasks->delete($this->findTask((int) $data['id']));

        return Response::json(['id' => (int) $data['id'], 'deleted' => true]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task id.')->required(),
        ];
    }
}
