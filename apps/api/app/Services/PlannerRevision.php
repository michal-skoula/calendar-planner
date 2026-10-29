<?php

namespace App\Services;

use App\Models\CalendarFeed;
use App\Models\Task;

/**
 * A cheap fingerprint of everything the planner page draws.
 *
 * Clients poll `GET /api/revision` and refetch only when the value changes. It
 * has to cover changes that arrive over CalDAV while a page is open, which no
 * request from that page could know about, and payloads the feed timer writes.
 * See CalendarFeed::signature() for why the feed half hashes rather than reading
 * `updated_at`.
 */
final class PlannerRevision
{
    public static function current(): string
    {
        return md5(
            Task::query()->count().':'.(string) Task::query()->max('updated_at')
            .'|'.CalendarFeed::signature()
        );
    }
}
