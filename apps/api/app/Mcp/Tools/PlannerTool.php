<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Tool;

/**
 * Shared shape for the planner's tools.
 *
 * Datetimes cross this boundary as naive local wall clock in the configured
 * display timezone, with no offset, so a value read from one tool can be passed
 * straight into another unchanged.
 */
abstract class PlannerTool extends Tool
{
    /** @return array<string, mixed> */
    protected function payload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,
            'tags' => $task->tags,
            'status' => $task->status,
            'start' => self::local($task->starts_at),
            'end' => self::local($task->ends_at),
            'all_day' => $task->is_all_day,
            'duration_min' => $task->starts_at !== null && $task->ends_at !== null
                ? (int) $task->starts_at->diffInMinutes($task->ends_at)
                : $task->duration_min,
            'deadline' => $task->deadline?->toDateString(),
            'done' => $task->isDone(),
            'completed_at' => self::local($task->completed_at),
        ];
    }

    protected function findTask(int $id): Task
    {
        return Task::query()->findOrFail($id);
    }

    protected static function zone(): string
    {
        return (string) config('caldav.display_timezone');
    }

    private static function local(?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone(self::zone())->format('Y-m-d\TH:i:s');
    }
}
