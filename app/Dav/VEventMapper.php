<?php

namespace App\Dav;

use App\Models\Task;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Sabre\DAV\Exception\UnsupportedMediaType;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader;

/**
 * The only place in the application that reads or writes iCalendar.
 *
 * Nothing above App\Dav parses an .ics: the UI, the MCP tools and the events feed
 * all read the mirror columns on `tasks`.
 */
final class VEventMapper
{
    private const UTC = 'UTC';

    /** Our own marker, so our payloads are distinguishable from Apple's. */
    public const TASK_HINT = 'X-PLANNER-TASK-ID';

    /**
     * Serialise a task into an iCalendar document.
     *
     * Pass the task's current `calendardata` as $existingIcs whenever one exists.
     * Properties are mutated in place so that VALARM, LOCATION, X-APPLE-* and
     * anything else Apple attached survives. Rebuilding from the task row instead
     * would mean that renaming a task in the browser silently deletes the alarm
     * you set on your phone.
     */
    public function toVEvent(Task $task, ?string $existingIcs = null): string
    {
        if ($task->ical_uid === null) {
            throw new \LogicException('Cannot serialise a task that has no ical_uid.');
        }
        if ($task->starts_at === null || $task->ends_at === null) {
            throw new \LogicException('Cannot serialise a task that has no span.');
        }

        if ($existingIcs !== null && trim($existingIcs) !== '') {
            $vcal = Reader::read($existingIcs, Reader::OPTION_FORGIVING);
            $vevent = $this->masterEvent($vcal);
        } else {
            $vcal = new VCalendar;
            $vevent = null;
        }

        if (! $vevent instanceof VEvent) {
            $vevent = $vcal->add('VEVENT', ['UID' => $task->ical_uid]);
        }

        $vevent->UID = $task->ical_uid;
        $vevent->SUMMARY = $this->summaryFor($task);

        if ($task->notes !== null && trim($task->notes) !== '') {
            $vevent->DESCRIPTION = $task->notes;
        } else {
            $vevent->remove('DESCRIPTION');
        }

        $tags = array_values(array_filter(array_map('strval', $task->tags ?? [])));
        $vevent->remove('CATEGORIES');
        if ($tags !== []) {
            $vevent->add('CATEGORIES', $tags);
        }

        $this->writeSpan($vevent, $task);

        // Lets the backend adopt this task on its very first schedule, when the UID
        // is not persisted yet. See TaskCalendarBackend::mirror().
        $vevent->remove(self::TASK_HINT);
        if ($task->exists) {
            $vevent->add(self::TASK_HINT, (string) $task->getKey());
        }

        $now = new \DateTimeImmutable('now', new DateTimeZone(self::UTC));
        $vevent->remove('DTSTAMP');
        $vevent->add('DTSTAMP', $now);
        $vevent->remove('LAST-MODIFIED');
        $vevent->add('LAST-MODIFIED', $now);

        return $vcal->serialize();
    }

    /**
     * Read one iCalendar document into normalised facts.
     *
     * @throws UnsupportedMediaType when the payload carries no usable VEVENT. This
     *                              surfaces to the client as 415 rather than a 500,
     *                              which is the difference between a sync error iOS
     *                              can report and one it swallows.
     */
    public function fromVEvent(string $ics): VEventFacts
    {
        try {
            $vcal = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            throw new UnsupportedMediaType('Could not parse the iCalendar payload: '.$e->getMessage());
        }

        if (! $vcal instanceof VCalendar) {
            throw new UnsupportedMediaType('Expected a VCALENDAR document.');
        }

        $vevent = $this->masterEvent($vcal);
        if (! $vevent instanceof VEvent) {
            throw new UnsupportedMediaType('This calendar accepts VEVENT components only.');
        }

        $uid = trim((string) $vevent->UID);
        if ($uid === '') {
            throw new UnsupportedMediaType('The VEVENT carries no UID.');
        }

        $summary = trim((string) ($vevent->SUMMARY ?? ''));
        $prefix = $this->donePrefix();
        $isDone = $prefix !== '' && str_starts_with($summary, trim($prefix));
        if ($isDone) {
            $summary = ltrim(mb_substr($summary, mb_strlen(trim($prefix))));
        }

        $notes = $vevent->DESCRIPTION === null ? null : (string) $vevent->DESCRIPTION;
        if ($notes !== null && trim($notes) === '') {
            $notes = null;
        }

        $tags = [];
        if ($vevent->CATEGORIES !== null) {
            $tags = array_values(array_filter(array_map('trim', $vevent->CATEGORIES->getParts())));
        }

        if ($vevent->DTSTART === null) {
            throw new UnsupportedMediaType('The VEVENT carries no DTSTART.');
        }

        /** @var DateTime $dtstart */
        $dtstart = $vevent->DTSTART;
        $isAllDay = ! $dtstart->hasTime();
        $startsAt = CarbonImmutable::instance($dtstart->getDateTime(new DateTimeZone(self::UTC)))
            ->setTimezone(self::UTC);

        $endsAt = $this->readEnd($vevent, $startsAt, $isAllDay);

        if ($endsAt <= $startsAt) {
            $endsAt = $isAllDay ? $startsAt->addDay() : $startsAt->addMinutes(
                (int) config('caldav.default_duration_min')
            );
        }

        $hint = $vevent->{self::TASK_HINT};
        $taskId = $hint === null ? null : (int) (string) $hint;

        return new VEventFacts(
            uid: $uid,
            title: $summary === '' ? 'Untitled' : $summary,
            notes: $notes,
            tags: $tags,
            startsAt: $startsAt,
            endsAt: $endsAt,
            isAllDay: $isAllDay,
            isDone: $isDone,
            taskId: $taskId > 0 ? $taskId : null,
        );
    }

    public function summaryFor(Task $task): string
    {
        return ($task->isDone() ? $this->donePrefix() : '').$task->title;
    }

    private function donePrefix(): string
    {
        return (string) config('caldav.done_prefix', '');
    }

    /**
     * The one VEVENT this task is carried by. Recurrence is out of scope for v1,
     * so an override instance (one carrying RECURRENCE-ID) is skipped rather than
     * mistaken for the master.
     */
    private function masterEvent(VCalendar $vcal): ?VEvent
    {
        $fallback = null;

        foreach ($vcal->select('VEVENT') as $candidate) {
            if (! $candidate instanceof VEvent) {
                continue;
            }
            $fallback ??= $candidate;
            if ($candidate->{'RECURRENCE-ID'} === null) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function readEnd(VEvent $vevent, CarbonImmutable $startsAt, bool $isAllDay): CarbonImmutable
    {
        if ($vevent->DTEND !== null) {
            /** @var DateTime $dtend */
            $dtend = $vevent->DTEND;

            return CarbonImmutable::instance($dtend->getDateTime(new DateTimeZone(self::UTC)))
                ->setTimezone(self::UTC);
        }

        if ($vevent->DURATION !== null) {
            $interval = DateTimeParser::parseDuration((string) $vevent->DURATION);

            return $startsAt->add($interval);
        }

        // RFC 5545: a DATE-valued DTSTART with no DTEND lasts one day; a
        // DATE-TIME one has zero duration, which is useless as a work block.
        return $isAllDay
            ? $startsAt->addDay()
            : $startsAt->addMinutes((int) config('caldav.default_duration_min'));
    }

    /**
     * An all-day span is written as date-only values, and DTEND stays exclusive —
     * the same convention `tasks.ends_at` and FullCalendar both use, so the value
     * never needs shifting by a day in either direction.
     */
    private function writeSpan(VEvent $vevent, Task $task): void
    {
        $vevent->remove('DTSTART');
        $vevent->remove('DTEND');
        $vevent->remove('DURATION');

        $start = $task->starts_at->copy()->utc();
        $end = $task->ends_at->copy()->utc();

        if ($task->is_all_day) {
            $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $end->format('Ymd'), ['VALUE' => 'DATE']);

            return;
        }

        $vevent->add('DTSTART', $start->toDateTimeImmutable());
        $vevent->add('DTEND', $end->toDateTimeImmutable());
    }
}
