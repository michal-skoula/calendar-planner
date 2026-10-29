<?php

namespace App\Services;

use App\Dav\TaskCalendar;
use App\Dav\TaskCalendarBackend;
use App\Dav\VEventMapper;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only caller of the CalDAV backend from our side of the application.
 *
 * It owns the inbox-only fields outright — title, notes, tags, deadline and the
 * duration estimate — and routes everything calendar-bearing through the backend.
 * To honour that split without breaking the invariant, the methods below build a
 * detached draft of the task, serialise it, and let the backend persist the real
 * values on the way out.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskCalendarBackend $backend,
        private readonly TaskCalendar $calendar,
        private readonly VEventMapper $mapper,
    ) {}

    /**
     * @param  array{title: string, notes?: string|null, tags?: array<int, string>, deadline?: string|\DateTimeInterface|null, duration_min?: int|null, start?: string|\DateTimeInterface|null}  $attributes
     */
    public function create(array $attributes): Task
    {
        $task = new Task;
        $task->title = trim((string) $attributes['title']);
        $task->notes = $attributes['notes'] ?? null;
        $task->tags = array_values($attributes['tags'] ?? []);
        $task->deadline = $attributes['deadline'] ?? null;
        $task->duration_min = $attributes['duration_min'] ?? null;
        $task->status = Task::STATUS_INBOX;
        $task->save();

        $start = $attributes['start'] ?? null;
        if ($start !== null && $start !== '') {
            // A naive string is wall clock in the display timezone; one that
            // carries its own offset is honoured as given.
            $at = CarbonImmutable::parse($start, (string) config('caldav.display_timezone'));

            return $this->schedule($task, $at, $task->duration_min);
        }

        return $task;
    }

    /** Put an inbox task on the calendar. Already-scheduled tasks are moved instead. */
    public function schedule(Task $task, CarbonImmutable $start, ?int $minutes = null): Task
    {
        if ($task->isScheduled()) {
            return $this->move($task, $start, $minutes);
        }

        $minutes = $minutes ?: $task->plannedDuration();
        $uid = (string) Str::uuid();

        $draft = clone $task;
        $draft->ical_uid = $uid;
        $draft->starts_at = $start->utc();
        $draft->ends_at = $start->utc()->addMinutes($minutes);
        $draft->is_all_day = false;

        $this->backend->createCalendarObject(
            $this->calendar->id(),
            $uid.'.ics',
            $this->mapper->toVEvent($draft),
        );

        return $task->refresh();
    }

    /**
     * Move or resize. Dragging an all-day event into a timed slot is a legitimate
     * action, so the span is always rewritten as date-time.
     */
    public function move(Task $task, CarbonImmutable $start, ?int $minutes = null): Task
    {
        $uri = $this->requireUri($task);

        $minutes = $minutes ?: $this->currentMinutes($task);

        $draft = clone $task;
        $draft->starts_at = $start->utc();
        $draft->ends_at = $start->utc()->addMinutes($minutes);
        $draft->is_all_day = false;

        $this->rewrite($task, $uri, $draft);

        return $task->refresh();
    }

    /** Take the task off the calendar. The task itself survives. */
    public function unschedule(Task $task): Task
    {
        if (! $task->isScheduled()) {
            return $task;
        }

        $this->backend->deleteCalendarObject($this->calendar->id(), $this->requireUri($task));

        return $task->refresh();
    }

    public function complete(Task $task): Task
    {
        if ($task->isDone()) {
            return $task;
        }

        if (! $task->isScheduled()) {
            $task->completed_at = CarbonImmutable::now();
            $task->status = Task::STATUS_DONE;
            $task->save();

            return $task;
        }

        $draft = clone $task;
        $draft->completed_at = CarbonImmutable::now();

        $this->rewrite($task, $this->requireUri($task), $draft);

        return $task->refresh();
    }

    public function reopen(Task $task): Task
    {
        if (! $task->isDone()) {
            return $task;
        }

        if (! $task->isScheduled()) {
            $task->completed_at = null;
            $task->status = Task::STATUS_INBOX;
            $task->save();

            return $task;
        }

        $draft = clone $task;
        $draft->completed_at = null;

        $this->rewrite($task, $this->requireUri($task), $draft);

        return $task->refresh();
    }

    /**
     * Change the fields this service owns. When the task is scheduled the event is
     * re-serialised so the new title and notes reach the phone.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Task $task, array $attributes): Task
    {
        foreach (['title', 'notes', 'tags', 'deadline', 'duration_min'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $task->{$field} = $field === 'tags'
                    ? array_values($attributes[$field] ?? [])
                    : $attributes[$field];
            }
        }

        if ($task->isDirty()) {
            $task->save();
        }

        if ($task->isScheduled()) {
            $this->rewrite($task, $this->requireUri($task), $task);
            $task->refresh();
        }

        return $task;
    }

    /** Remove the task entirely, taking its calendar object with it. */
    public function delete(Task $task): void
    {
        if ($task->isScheduled()) {
            $this->backend->deleteCalendarObject($this->calendar->id(), $this->requireUri($task));
            $task->refresh();
        }

        $task->delete();
    }

    /**
     * Push a draft's state onto the existing calendar object, keeping every
     * property the device added to it.
     */
    private function rewrite(Task $task, string $uri, Task $draft): void
    {
        $existing = $this->backend->objectData($this->calendar->id(), $uri);

        $this->backend->updateCalendarObject(
            $this->calendar->id(),
            $uri,
            $this->mapper->toVEvent($draft, $existing),
        );
    }

    private function currentMinutes(Task $task): int
    {
        if ($task->starts_at !== null && $task->ends_at !== null) {
            $minutes = $task->starts_at->diffInMinutes($task->ends_at);
            if ($minutes > 0) {
                return (int) $minutes;
            }
        }

        return $task->plannedDuration();
    }

    private function requireUri(Task $task): string
    {
        $uri = $task->objectUri();

        if ($uri === null) {
            throw new RuntimeException("Task {$task->getKey()} is not on the calendar.");
        }

        return $uri;
    }
}
