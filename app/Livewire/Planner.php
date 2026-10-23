<?php

namespace App\Livewire;

use App\Models\Task;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The planner: an inbox pane beside a week view.
 *
 * Every mutation goes through TaskService, which reaches the calendar through
 * App\Dav\TaskCalendarBackend. This component never touches a calendar table and
 * never parses iCalendar — it reads the mirror columns on `tasks`.
 *
 * Times cross to the browser as floating wall-clock in the configured display
 * timezone, and FullCalendar runs with its default `local` zone. That way the
 * grid shows Prague time whatever the laptop is set to, and a dragged event comes
 * back as the same wall clock it was dropped on.
 */
#[Layout('components.layouts.app')]
class Planner extends Component
{
    #[Validate('required|string|max:255')]
    public string $newTitle = '';

    #[Validate('nullable|integer|min:5|max:1440')]
    public ?int $newDuration = null;

    public string $signature = '';

    public function mount(): void
    {
        $this->signature = self::currentSignature();
    }

    /**
     * Picks up changes made on a device. Only a real change dispatches, so the
     * calendar is not refetched on every tick. The calendar container itself is
     * wire:ignore'd, so this component re-rendering never disturbs a drag.
     */
    public function heartbeat(): void
    {
        $signature = self::currentSignature();

        if ($signature !== $this->signature) {
            $this->signature = $signature;
            $this->dispatch('calendar-stale');
        }
    }

    public function addTask(TaskService $tasks): void
    {
        $this->validate();

        $tasks->create([
            'title' => $this->newTitle,
            'duration_min' => $this->newDuration,
        ]);

        $this->reset('newTitle', 'newDuration');
        $this->signature = self::currentSignature();
    }

    /**
     * FullCalendar's event source. Returns only what the visible range needs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function events(string $start, string $end): array
    {
        $zone = self::zone();

        $from = CarbonImmutable::parse($start, $zone)->utc();
        $to = CarbonImmutable::parse($end, $zone)->utc();

        return Task::query()
            ->overlapping($from, $to)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Task $task): array => self::toEvent($task))
            ->all();
    }

    public function scheduleFromInbox(int $taskId, string $start, ?int $minutes, TaskService $tasks): void
    {
        $task = Task::query()->findOrFail($taskId);

        $tasks->schedule($task, CarbonImmutable::parse($start, self::zone()), $minutes);

        $this->signature = self::currentSignature();
    }

    public function moveEvent(int $taskId, string $start, string $end, TaskService $tasks): void
    {
        $task = Task::query()->findOrFail($taskId);

        $zone = self::zone();
        $from = CarbonImmutable::parse($start, $zone);
        $minutes = (int) $from->diffInMinutes(CarbonImmutable::parse($end, $zone));

        $tasks->move($task, $from, $minutes > 0 ? $minutes : null);

        $this->signature = self::currentSignature();
    }

    public function unscheduleEvent(int $taskId, TaskService $tasks): void
    {
        $tasks->unschedule(Task::query()->findOrFail($taskId));

        $this->signature = self::currentSignature();
        $this->dispatch('calendar-stale');
    }

    public function toggleComplete(int $taskId, TaskService $tasks): void
    {
        $task = Task::query()->findOrFail($taskId);

        $task->isDone() ? $tasks->reopen($task) : $tasks->complete($task);

        $this->signature = self::currentSignature();
        $this->dispatch('calendar-stale');
    }

    public function deleteTask(int $taskId, TaskService $tasks): void
    {
        $tasks->delete(Task::query()->findOrFail($taskId));

        $this->signature = self::currentSignature();
        $this->dispatch('calendar-stale');
    }

    public function render(): View
    {
        return view('livewire.planner', [
            'inbox' => $this->inbox(),
            'doneToday' => $this->doneToday(),
        ]);
    }

    /** @return Collection<int, Task> */
    private function inbox(): Collection
    {
        return Task::query()
            ->inbox()
            ->orderByRaw('deadline is null, deadline asc')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, Task> */
    private function doneToday(): Collection
    {
        return Task::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', CarbonImmutable::now(self::zone())->startOfDay()->utc())
            ->orderByDesc('completed_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private static function toEvent(Task $task): array
    {
        $zone = self::zone();
        $start = $task->starts_at->copy()->setTimezone($zone);
        $end = $task->ends_at->copy()->setTimezone($zone);

        return [
            'id' => (string) $task->id,
            'title' => $task->title,
            // Floating wall clock, no offset: see the class docblock.
            'start' => $task->is_all_day ? $start->toDateString() : $start->format('Y-m-d\TH:i:s'),
            'end' => $task->is_all_day ? $end->toDateString() : $end->format('Y-m-d\TH:i:s'),
            'allDay' => $task->is_all_day,
            'classNames' => $task->isDone() ? ['planner-done'] : [],
            'extendedProps' => [
                'done' => $task->isDone(),
                'tags' => $task->tags,
                'allDayFromDevice' => $task->is_all_day,
            ],
        ];
    }

    private static function zone(): string
    {
        return (string) config('caldav.display_timezone');
    }

    /**
     * Cheap proof that something changed, including changes that arrived over
     * CalDAV while this page was open.
     */
    private static function currentSignature(): string
    {
        return Task::query()->count().':'.(string) Task::query()->max('updated_at');
    }
}
