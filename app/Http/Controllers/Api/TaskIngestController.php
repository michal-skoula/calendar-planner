<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/tasks — the iOS Shortcut entry point.
 *
 * A `start` makes the task scheduled; without one it lands in the inbox.
 */
class TaskIngestController extends Controller
{
    public function __invoke(Request $request, TaskService $tasks): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'start' => ['nullable', 'date'],
            'duration_min' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'deadline' => ['nullable', 'date'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);

        $task = $tasks->create([
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? [],
            'deadline' => $data['deadline'] ?? null,
            'duration_min' => $data['duration_min'] ?? null,
            'start' => $data['start'] ?? null,
        ]);

        return response()->json([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'starts_at' => $task->starts_at?->toIso8601String(),
            'ends_at' => $task->ends_at?->toIso8601String(),
            'deadline' => $task->deadline?->toDateString(),
            'tags' => $task->tags,
        ], 201);
    }
}
