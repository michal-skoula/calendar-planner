<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('unschedule_task')]
#[Description('Take a task off the calendar. The task returns to the inbox and keeps its title, notes, tags, deadline and estimate.')]
class UnscheduleTaskTool extends PlannerTool
{
    public function handle(Request $request, TaskService $tasks): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        return Response::json($this->payload($tasks->unschedule($this->findTask((int) $data['id']))));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task id.')->required(),
        ];
    }
}
