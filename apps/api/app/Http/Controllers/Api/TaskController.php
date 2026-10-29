<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\WallClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /api/tasks — everything a client can do to a task.
 *
 * Every write goes through TaskService, which reaches the calendar through
 * App\Dav\TaskCalendarBackend. Nothing here touches a calendar table, parses
 * iCalendar, or writes a calendar-bearing column on `tasks` itself. The
 * controller's whole job is validation and translating wall clock.
 *
 * Route model binding hands each action a Task, and answers 404 on its own when
 * the id does not exist.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * GET /api/tasks
     *
     * One endpoint serves the inbox, the calendar range and "done today"; the
     * filters combine with AND. Ordering is fixed: scheduled tasks by start,
     * then unscheduled ones by deadline (none last), newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:inbox,scheduled,done'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'completed_after' => ['nullable', 'date'],
            'tag' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = Task::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // A window over the calendar: anything whose span overlaps [from, to).
        if (isset($filters['from'])) {
            $query->whereNotNull('ends_at')->where('ends_at', '>', WallClock::parse($filters['from'])->utc());
        }

        if (isset($filters['to'])) {
            $query->whereNotNull('starts_at')->where('starts_at', '<', WallClock::parse($filters['to'])->utc());
        }

        if (isset($filters['completed_after'])) {
            $query->whereNotNull('completed_at')
                ->where('completed_at', '>=', WallClock::parse($filters['completed_after'])->utc());
        }

        if (isset($filters['tag'])) {
            // tags is a json column; a LIKE on the encoded array is enough for
            // the handful of tags a single user keeps.
            $query->where('tags', 'like', '%"'.$filters['tag'].'"%');
        }

        $tasks = $query
            ->orderByRaw('starts_at is null, starts_at asc')
            ->orderByRaw('deadline is null, deadline asc')
            ->orderByDesc('id')
            ->limit($filters['limit'] ?? 500)
            ->get();

        return TaskResource::collection($tasks);
    }

    /**
     * POST /api/tasks
     *
     * Also the iOS Shortcut's endpoint, which is why `start` is accepted here
     * rather than only on /schedule: one request can create and schedule.
     */
    public function store(Request $request): TaskResource
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'start' => ['nullable', 'date'],
            'duration_min' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'deadline' => ['nullable', 'date_format:Y-m-d'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);

        $task = $this->tasks->create([
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? [],
            'deadline' => $data['deadline'] ?? null,
            'duration_min' => $data['duration_min'] ?? null,
            'start' => $data['start'] ?? null,
        ]);

        return new TaskResource($task);
    }

    /** GET /api/tasks/{task} */
    public function show(Task $task): TaskResource
    {
        return new TaskResource($task);
    }

    /**
     * PATCH /api/tasks/{task}
     *
     * Only the fields TaskService owns outright. Where a task sits on the
     * calendar changes through /schedule and /unschedule, never here.
     */
    public function update(Request $request, Task $task): TaskResource
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'duration_min' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);

        return new TaskResource($this->tasks->updateDetails($task, $data));
    }

    /** DELETE /api/tasks/{task} — takes the calendar event with it. */
    public function destroy(Task $task): Response
    {
        $this->tasks->delete($task);

        return response()->noContent();
    }

    /**
     * POST /api/tasks/{task}/schedule
     *
     * Puts an inbox task on the calendar, or moves one that is already there.
     * The length comes from `end` if given, else `duration_min`, else the
     * task's own estimate, else the configured default.
     */
    public function schedule(Request $request, Task $task): TaskResource
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['nullable', 'date', 'after:start'],
            'duration_min' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $start = WallClock::parse($data['start']);

        $minutes = isset($data['end'])
            ? (int) $start->diffInMinutes(WallClock::parse($data['end']))
            : ($data['duration_min'] ?? null);

        return new TaskResource($this->tasks->schedule($task, $start, $minutes ?: null));
    }

    /** POST /api/tasks/{task}/unschedule — back to the inbox, task intact. */
    public function unschedule(Task $task): TaskResource
    {
        return new TaskResource($this->tasks->unschedule($task));
    }

    /** POST /api/tasks/{task}/complete — a no-op on a task already done. */
    public function complete(Task $task): TaskResource
    {
        return new TaskResource($this->tasks->complete($task));
    }

    /** POST /api/tasks/{task}/reopen — a no-op on a task not done. */
    public function reopen(Task $task): TaskResource
    {
        return new TaskResource($this->tasks->reopen($task));
    }
}
