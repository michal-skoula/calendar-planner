<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A task. Either it sits in the inbox with no calendar object, or it is scheduled
 * and exactly one VEVENT carries it to Apple Calendar.
 *
 * There are deliberately NO model observers on this class. The five
 * calendar-bearing attributes below are written in exactly three places — the
 * create, update and delete overrides on App\Dav\TaskCalendarBackend — which is
 * what makes a write loop between `tasks` and `calendarobjects` structurally
 * impossible rather than merely unlikely.
 *
 * @property int $id
 * @property string $title
 * @property string|null $notes
 * @property array<int, string> $tags
 * @property Carbon|null $deadline
 * @property int|null $duration_min
 * @property Carbon|null $completed_at
 * @property string $status
 * @property string|null $ical_uid
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_all_day
 */
class Task extends Model
{
    public const STATUS_INBOX = 'inbox';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'title',
        'notes',
        'tags',
        'deadline',
        'duration_min',
        'completed_at',
        'status',
        'ical_uid',
        'starts_at',
        'ends_at',
        'is_all_day',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'deadline' => 'date',
            'duration_min' => 'integer',
            'completed_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
        ];
    }

    public function isScheduled(): bool
    {
        return $this->ical_uid !== null;
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }

    /** The calendar object URI this task's VEVENT lives at, if any. */
    public function objectUri(): ?string
    {
        return $this->ical_uid === null ? null : $this->ical_uid.'.ics';
    }

    /** Minutes to use when this task is dropped onto the calendar. */
    public function plannedDuration(): int
    {
        return $this->duration_min ?: (int) config('caldav.default_duration_min');
    }

    public function scopeInbox(Builder $query): void
    {
        $query->where('status', self::STATUS_INBOX);
    }

    public function scopeScheduled(Builder $query): void
    {
        $query->whereNotNull('ical_uid');
    }

    /** Scheduled tasks whose span overlaps the half-open window [$start, $end). */
    public function scopeOverlapping(Builder $query, DateTimeInterface $start, DateTimeInterface $end): void
    {
        $query->whereNotNull('starts_at')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
