<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Support\WallClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task as every client sees it. Schema: `Task` in openapi.yaml.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'notes' => $this->notes,
            'tags' => $this->tags ?? [],
            'status' => $this->status,
            'done' => $this->isDone(),
            'deadline' => $this->deadline?->toDateString(),

            // The estimate the user typed, which may be empty, and the length a
            // drop onto the calendar will actually use.
            'duration_min' => $this->duration_min,
            'planned_duration_min' => $this->plannedDuration(),

            // An all-day span is dates only, with an exclusive end, exactly as
            // iCalendar has it. See the Task schema.
            'start' => $this->is_all_day
                ? $this->starts_at?->copy()->setTimezone(WallClock::zone())->toDateString()
                : WallClock::local($this->starts_at),
            'end' => $this->is_all_day
                ? $this->ends_at?->copy()->setTimezone(WallClock::zone())->toDateString()
                : WallClock::local($this->ends_at),
            // Cast: a task created in this request has not been re-read, so
            // the column's database default is not on the model yet.
            'all_day' => (bool) $this->is_all_day,

            'completed_at' => WallClock::instant($this->completed_at),
            'created_at' => WallClock::instant($this->created_at),
            'updated_at' => WallClock::instant($this->updated_at),
        ];
    }
}
