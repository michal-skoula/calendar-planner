<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_tasks')]
#[Description('List tasks, optionally filtered by status, by a date range over their scheduled time, or by tag.')]
class ListTasksTool extends PlannerTool
{
    public function handle(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:inbox,scheduled,done,all'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'tag' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Task::query();

        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $zone = self::zone();

        if (isset($filters['from'])) {
            $query->where('ends_at', '>', CarbonImmutable::parse($filters['from'], $zone)->utc());
        }

        if (isset($filters['to'])) {
            $query->where('starts_at', '<', CarbonImmutable::parse($filters['to'], $zone)->utc());
        }

        if (isset($filters['tag'])) {
            // tags is a json column; a LIKE on the encoded array is enough for the
            // handful of tags a single user keeps.
            $query->where('tags', 'like', '%"'.$filters['tag'].'"%');
        }

        $tasks = $query
            ->orderByRaw('starts_at is null, starts_at asc')
            ->orderByDesc('id')
            ->limit($filters['limit'] ?? 100)
            ->get();

        return Response::json([
            'count' => $tasks->count(),
            'timezone' => $zone,
            'tasks' => $tasks->map(fn (Task $task): array => $this->payload($task))->all(),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['inbox', 'scheduled', 'done', 'all'])
                ->description('Defaults to all.'),
            'from' => $schema->string()
                ->description('Only tasks scheduled to end after this local datetime, e.g. 2026-10-26T00:00:00.'),
            'to' => $schema->string()
                ->description('Only tasks scheduled to start before this local datetime.'),
            'tag' => $schema->string()->description('Exact tag match.'),
            'limit' => $schema->integer()->description('Defaults to 100, maximum 200.'),
        ];
    }
}
