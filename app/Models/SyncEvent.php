<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncEvent extends Model
{
    public const SOURCE_CALDAV = 'caldav';

    public const SOURCE_PLANNER = 'planner';

    public $timestamps = false;

    protected $fillable = ['kind', 'source', 'client', 'task_id', 'title', 'detail', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function isFailure(): bool
    {
        return in_array($this->kind, ['auth_refused', 'error'], true);
    }

    /** Phrased for someone reading a list, not for a log parser. */
    public function sentence(): string
    {
        $where = $this->source === self::SOURCE_CALDAV
            ? 'from '.($this->client ?? 'a calendar app')
            : 'in the planner';

        return match ($this->kind) {
            'created' => "appeared {$where}",
            'scheduled' => "scheduled {$where}",
            'moved' => "moved {$where}",
            'edited' => "edited {$where}",
            'completed' => "completed {$where}",
            'reopened' => "reopened {$where}",
            'unscheduled' => "returned to the inbox {$where}",
            'auth_refused' => 'sign-in refused',
            'error' => 'server error',
            default => $this->kind,
        };
    }
}
