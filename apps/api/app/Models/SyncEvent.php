<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncEvent extends Model
{
    public const SOURCE_CALDAV = 'caldav';

    public const SOURCE_PLANNER = 'planner';

    /** A subscribed calendar being re-fetched. Never a task change. */
    public const SOURCE_FEED = 'feed';

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
        return in_array($this->kind, ['auth_refused', 'error', 'feed_failed'], true);
    }

    /** Phrased for someone reading a list, not for a log parser. */
    public function sentence(): string
    {
        if ($this->source === self::SOURCE_FEED) {
            return match ($this->kind) {
                'feed_updated' => 'was re-fetched',
                'feed_failed' => 'could not be fetched',
                'feed_series_dropped' => 'has a recurring event this server cannot read',
                default => $this->kind,
            };
        }

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
