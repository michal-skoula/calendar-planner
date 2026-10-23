<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_task')]
#[Description('Fetch one task by id.')]
class GetTaskTool extends PlannerTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        return Response::json($this->payload($this->findTask((int) $data['id'])));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The task id.')->required(),
        ];
    }
}
