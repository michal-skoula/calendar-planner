<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A calendar someone else owns, read over HTTP and drawn behind the task grid.
 *
 * The `payload` column holds the last response body that parsed as a calendar,
 * verbatim. A failed fetch leaves it alone, which is why the grid keeps working
 * when a feed's server does not. Expansion happens on read — see
 * App\Feeds\FeedExpander — so there is no occurrences table and nothing here
 * goes stale in a way that needs migrating.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $url_hash
 * @property string $color
 * @property bool $is_enabled
 * @property int $refresh_min
 * @property int $sort_order
 * @property string|null $payload
 * @property string|null $payload_hash
 * @property int|null $payload_bytes
 * @property int|null $series_count
 * @property int|null $dropped_count
 * @property string|null $etag
 * @property string|null $last_modified
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $last_success_at
 * @property int|null $last_status
 * @property string|null $last_error
 */
class CalendarFeed extends Model
{
    protected $fillable = [
        'name',
        'url',
        'url_hash',
        'color',
        'is_enabled',
        'refresh_min',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'is_enabled' => 'boolean',
            'refresh_min' => 'integer',
            'sort_order' => 'integer',
            'payload_bytes' => 'integer',
            'series_count' => 'integer',
            'dropped_count' => 'integer',
            'last_status' => 'integer',
            'last_fetched_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    /** The value `url_hash` must hold for a given URL. Normalise first. */
    public static function hashFor(string $normalisedUrl): string
    {
        return hash('sha256', $normalisedUrl);
    }

    public function hasPayload(): bool
    {
        return $this->payload !== null && $this->payload !== '';
    }

    /** Due for a re-fetch. A feed that has never succeeded is always due. */
    public function isStale(): bool
    {
        if ($this->last_success_at === null) {
            return true;
        }

        return $this->last_success_at->addMinutes($this->refresh_min)->isPast();
    }

    /**
     * Whether this feed is working right now.
     *
     * Two ways to fail: the data has gone stale, or the most recent attempt
     * errored. The staleness window is twice the interval, because one missed
     * refresh is normal — the timer is randomised and providers rate-limit — but
     * an outright failure shows immediately, and clears on the next success,
     * which is at most one timer tick away.
     */
    public function isHealthy(): bool
    {
        if ($this->last_success_at === null || $this->last_error !== null) {
            return false;
        }

        return $this->last_success_at->addMinutes($this->refresh_min * 2)->isFuture();
    }

    /** Phrased for the feeds page, not for a log parser. */
    public function statusLine(): string
    {
        if ($this->last_success_at === null) {
            return $this->last_error ?? 'never fetched';
        }

        $when = 'checked '.$this->last_success_at->diffForHumans();

        if ($this->last_error !== null) {
            return $when.' · last attempt failed: '.$this->last_error;
        }

        if ($this->series_count === null) {
            return $when;
        }

        $dropped = $this->dropped_count > 0 ? ', '.$this->dropped_count.' unreadable' : '';

        return $when.' · '.$this->series_count.' series'.$dropped;
    }

    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Cheap proof that something about the feeds changed, for the planner's
     * heartbeat.
     *
     * Hashing the payload hashes rather than reading `updated_at`: a refresh
     * landing in the same second as the previous write leaves that timestamp
     * identical, and the open page would never refetch. This also covers a
     * rename, a recolour and hide/show, all of which change what the grid
     * should draw. The table holds one row per subscription, so the query is
     * cheaper than the render it guards.
     */
    public static function signature(): string
    {
        $rows = self::query()
            ->ordered()
            ->get(['id', 'payload_hash', 'is_enabled', 'color', 'name'])
            ->map(fn (self $feed): string => implode('|', [
                $feed->id,
                (string) $feed->payload_hash,
                (int) $feed->is_enabled,
                $feed->color,
                $feed->name,
            ]))
            ->implode(';');

        return md5($rows);
    }

    /**
     * Feeds worth drawing: enabled, and holding something that parsed.
     *
     * @return Collection<int, self>
     */
    public static function renderable(): Collection
    {
        return self::query()
            ->enabled()
            ->ordered()
            ->whereNotNull('payload')
            ->get();
    }
}
