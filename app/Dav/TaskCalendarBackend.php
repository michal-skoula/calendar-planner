<?php

namespace App\Dav;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Sabre\CalDAV\Backend\PDO as SabrePDO;

/**
 * The chokepoint.
 *
 * Every write that can change whether a task is scheduled passes through here —
 * the Livewire UI, the MCP tools and the Shortcut endpoint by way of
 * App\Services\TaskService, and Apple Calendar by way of sabre's own server. The
 * three overrides below are the ONLY code permitted to write `tasks.ical_uid`,
 * `status`, `starts_at`, `ends_at` or `is_all_day`.
 *
 * Because our own writes come back through the same `createCalendarObject` as
 * Apple's, there is no second code path to keep in step, and because no model
 * observer exists there is no loop to guard against.
 */
final class TaskCalendarBackend extends SabrePDO
{
    public function __construct(private readonly VEventMapper $mapper)
    {
        parent::__construct(DB::connection()->getPdo());
    }

    /**
     * Called both when we schedule a task we already hold and when Apple invents
     * an event we have never seen. It reconciles on identity rather than branching
     * on the caller.
     */
    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $facts = $this->mapper->fromVEvent(self::asString($calendarData));

        return DB::transaction(function () use ($calendarId, $objectUri, $calendarData, $facts) {
            $etag = parent::createCalendarObject($calendarId, $objectUri, $calendarData);
            $this->mirror($facts);

            return $etag;
        });
    }

    /**
     * A move, a resize or an edit. Apple sends this when you drag an event on the
     * phone; we send it when a title changes in the browser.
     */
    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $facts = $this->mapper->fromVEvent(self::asString($calendarData));

        return DB::transaction(function () use ($calendarId, $objectUri, $calendarData, $facts) {
            $etag = parent::updateCalendarObject($calendarId, $objectUri, $calendarData);
            $this->mirror($facts);

            return $etag;
        });
    }

    /**
     * Deleting the event unschedules the task. It never destroys it: the title,
     * notes, tags, deadline and estimate are the part you would not want to lose
     * by swiping on a phone.
     */
    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $uid = $this->uidAt($calendarId, $objectUri);

        DB::transaction(function () use ($calendarId, $objectUri, $uid) {
            parent::deleteCalendarObject($calendarId, $objectUri);

            if ($uid === null) {
                return;
            }

            $task = Task::query()->where('ical_uid', $uid)->first();
            if ($task === null) {
                return;
            }

            $task->ical_uid = null;
            $task->starts_at = null;
            $task->ends_at = null;
            $task->is_all_day = false;
            // A finished task that loses its event stays finished. Only unfinished
            // work falls back to the inbox.
            $task->status = $task->isDone() ? Task::STATUS_DONE : Task::STATUS_INBOX;
            $task->save();
        });
    }

    /** The raw iCalendar currently stored at a URI, or null. */
    public function objectData(array $calendarId, string $objectUri): ?string
    {
        $row = $this->getCalendarObject($calendarId, $objectUri);

        return is_array($row) && isset($row['calendardata'])
            ? self::asString($row['calendardata'])
            : null;
    }

    /**
     * Adopt the task this VEVENT belongs to, or create one if it belongs to none.
     *
     * Resolution is by UID first. On a task's very first schedule the UID is not
     * yet persisted — the invariant forbids writing it anywhere but here — so our
     * own payloads carry an X-PLANNER-TASK-ID hint. That hint is honoured only
     * while the named task is unscheduled, so duplicating one of our events on a
     * device produces a second task rather than hijacking the first.
     */
    private function mirror(VEventFacts $facts): Task
    {
        $task = Task::query()->where('ical_uid', $facts->uid)->first();

        if ($task === null && $facts->taskId !== null) {
            $candidate = Task::query()->whereKey($facts->taskId)->first();
            if ($candidate !== null && $candidate->ical_uid === null) {
                $task = $candidate;
            }
        }

        if ($task === null) {
            $task = new Task;
        }

        $task->ical_uid = $facts->uid;
        $task->title = $facts->title;
        $task->notes = $facts->notes;
        $task->tags = $facts->tags;
        $task->starts_at = $facts->startsAt;
        $task->ends_at = $facts->endsAt;
        $task->is_all_day = $facts->isAllDay;

        if ($facts->isDone) {
            if ($task->completed_at === null) {
                $task->completed_at = CarbonImmutable::now();
            }
            $task->status = Task::STATUS_DONE;
        } else {
            $task->completed_at = null;
            $task->status = Task::STATUS_SCHEDULED;
        }

        $task->save();

        return $task;
    }

    /** The UID stored at a URI, read before the row is deleted. */
    private function uidAt($calendarId, string $objectUri): ?string
    {
        $data = null;

        try {
            $row = $this->getCalendarObject($calendarId, $objectUri);
            $data = is_array($row) && isset($row['calendardata'])
                ? self::asString($row['calendardata'])
                : null;
        } catch (\Throwable) {
            return null;
        }

        if ($data === null) {
            return null;
        }

        try {
            return $this->mapper->fromVEvent($data)->uid;
        } catch (\Throwable) {
            // An unparseable object still has to be deletable.
            return null;
        }
    }

    /** sabre hands calendar data over as a string or as a stream, depending on driver. */
    private static function asString(mixed $calendarData): string
    {
        if (is_resource($calendarData)) {
            return (string) stream_get_contents($calendarData);
        }

        return (string) $calendarData;
    }
}
