<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sabre\DAV\Exception\UnsupportedMediaType;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * The chokepoint, driven from both sides. Apple's half is exercised by calling the
 * backend overrides with payloads shaped the way iOS sends them.
 */
class CalDavSyncTest extends TestCase
{
    use ProvisionsCalendar, RefreshDatabase;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
        $this->tasks = app(TaskService::class);
    }

    public function test_scheduling_an_inbox_task_creates_one_object_and_mirrors_the_span(): void
    {
        $task = $this->tasks->create(['title' => 'Fix the redirect', 'duration_min' => 45]);

        $this->assertSame(Task::STATUS_INBOX, $task->status);
        $this->assertSame(0, $this->objectCount());

        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));

        $this->assertSame(Task::STATUS_SCHEDULED, $task->status);
        $this->assertNotNull($task->ical_uid);
        $this->assertSame('2026-10-26 08:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-10-26 08:45:00', $task->ends_at->utc()->toDateTimeString());
        $this->assertFalse($task->is_all_day);
        $this->assertSame(1, $this->objectCount());
        $this->assertSame(1, Task::count());
    }

    public function test_our_own_schedule_does_not_create_a_second_task(): void
    {
        $task = $this->tasks->create(['title' => 'One task only']);
        $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));

        $this->assertSame(1, Task::count());
        $this->assertSame(1, $this->objectCount());
    }

    public function test_an_unseen_vevent_becomes_a_new_scheduled_task(): void
    {
        $this->backend()->createCalendarObject(
            $this->calendarId(),
            'apple-1.ics',
            $this->appleEvent('apple-1', ['DESCRIPTION' => 'about the filling']),
        );

        $task = Task::where('ical_uid', 'apple-1')->sole();

        $this->assertSame(Task::STATUS_SCHEDULED, $task->status);
        $this->assertSame('Call the dentist', $task->title);
        $this->assertSame('about the filling', $task->notes);
        $this->assertSame('2026-10-29 08:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(1, Task::count());
    }

    public function test_apple_moving_an_event_updates_only_the_span(): void
    {
        $task = $this->tasks->create(['title' => 'Gym', 'tags' => ['health'], 'deadline' => '2026-11-01']);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));

        $moved = str_replace(
            ['DTSTART:20261026T080000Z', 'DTEND:20261026T083000Z'],
            ['DTSTART:20261027T140000Z', 'DTEND:20261027T153000Z'],
            $this->backend()->objectData($this->calendarId(), $task->objectUri()),
        );

        $this->backend()->updateCalendarObject($this->calendarId(), $task->objectUri(), $moved);
        $task->refresh();

        $this->assertSame('2026-10-27 14:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-10-27 15:30:00', $task->ends_at->utc()->toDateTimeString());
        $this->assertSame('Gym', $task->title);
        $this->assertSame(['health'], $task->tags);
        $this->assertSame('2026-11-01', $task->deadline->toDateString());
    }

    public function test_apple_deleting_an_event_returns_the_task_to_the_inbox_and_keeps_its_fields(): void
    {
        $task = $this->tasks->create([
            'title' => 'Archive music',
            'notes' => 'youtube and spotify',
            'tags' => ['personal'],
            'deadline' => '2026-12-01',
            'duration_min' => 60,
        ]);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));
        $uri = $task->objectUri();

        $this->backend()->deleteCalendarObject($this->calendarId(), $uri);
        $task->refresh();

        $this->assertSame(Task::STATUS_INBOX, $task->status);
        $this->assertNull($task->ical_uid);
        $this->assertNull($task->starts_at);
        $this->assertNull($task->ends_at);
        $this->assertSame('Archive music', $task->title);
        $this->assertSame('youtube and spotify', $task->notes);
        $this->assertSame(['personal'], $task->tags);
        $this->assertSame('2026-12-01', $task->deadline->toDateString());
        $this->assertSame(60, $task->duration_min);
        $this->assertSame(0, $this->objectCount());
    }

    public function test_renaming_a_task_preserves_an_alarm_apple_added(): void
    {
        $task = $this->tasks->create(['title' => 'Standup registration']);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));
        $uri = $task->objectUri();

        $withAlarm = str_replace(
            'END:VEVENT',
            "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Reminder\r\nEND:VALARM\r\nEND:VEVENT",
            $this->backend()->objectData($this->calendarId(), $uri),
        );
        $this->backend()->updateCalendarObject($this->calendarId(), $uri, $withAlarm);

        $this->tasks->updateDetails($task->refresh(), ['title' => 'Register for standup']);

        $stored = $this->backend()->objectData($this->calendarId(), $uri);

        $this->assertStringContainsString('BEGIN:VALARM', $stored);
        $this->assertStringContainsString('TRIGGER:-PT15M', $stored);
        $this->assertStringContainsString('SUMMARY:Register for standup', $stored);
    }

    public function test_completing_prefixes_the_summary_once_and_reopening_removes_it(): void
    {
        $task = $this->tasks->create(['title' => 'Write the README']);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));
        $uri = $task->objectUri();

        $task = $this->tasks->complete($task);
        $this->assertSame(Task::STATUS_DONE, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertStringContainsString('SUMMARY:✅ Write the README', $this->backend()->objectData($this->calendarId(), $uri));
        $this->assertSame('Write the README', $task->title, 'the prefix must not leak into the task title');

        $completedAt = $task->completed_at;
        $task = $this->tasks->complete($task);
        $stored = $this->backend()->objectData($this->calendarId(), $uri);
        $this->assertSame(1, substr_count($stored, '✅'), 'completing twice must not double the prefix');
        $this->assertEquals($completedAt, $task->completed_at, 'the original completion time must survive');

        $task = $this->tasks->reopen($task);
        $this->assertSame(Task::STATUS_SCHEDULED, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertStringContainsString('SUMMARY:Write the README', $this->backend()->objectData($this->calendarId(), $uri));
        $this->assertStringNotContainsString('✅', $this->backend()->objectData($this->calendarId(), $uri));
    }

    public function test_an_inbox_task_can_be_completed_without_reaching_the_calendar(): void
    {
        $task = $this->tasks->complete($this->tasks->create(['title' => 'Quick thing']));

        $this->assertSame(Task::STATUS_DONE, $task->status);
        $this->assertNull($task->ical_uid);
        $this->assertSame(0, $this->objectCount());
    }

    public function test_a_finished_task_stays_finished_when_its_event_is_deleted(): void
    {
        $task = $this->tasks->create(['title' => 'Already done']);
        $task = $this->tasks->complete($this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague')));

        $this->backend()->deleteCalendarObject($this->calendarId(), $task->objectUri());
        $task->refresh();

        $this->assertSame(Task::STATUS_DONE, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertNull($task->ical_uid);
    }

    public function test_an_all_day_event_from_ios_yields_a_whole_day_span(): void
    {
        // Date-valued DTSTART/DTEND, which is how iOS sends an all-day event. The
        // UI never creates one, but tolerating it is what stops an innocent action
        // on the phone from corrupting a task.
        $this->backend()->createCalendarObject($this->calendarId(), 'trip.ics',
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Apple//EN\r\nBEGIN:VEVENT\r\nUID:trip\r\n"
            ."SUMMARY:Trip České Švýcarsko\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261103\r\n"
            ."DTSTAMP:20261023T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $task = Task::where('ical_uid', 'trip')->sole();

        $this->assertTrue($task->is_all_day);
        $this->assertSame('Trip České Švýcarsko', $task->title);
        $this->assertSame('2026-11-01 00:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame('2026-11-03 00:00:00', $task->ends_at->utc()->toDateTimeString(), 'DTEND stays exclusive');
    }

    public function test_dragging_an_all_day_event_into_a_timed_slot_converts_it(): void
    {
        $this->backend()->createCalendarObject($this->calendarId(), 'trip2.ics',
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Apple//EN\r\nBEGIN:VEVENT\r\nUID:trip2\r\n"
            ."SUMMARY:Trip\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\n"
            ."DTSTAMP:20261023T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $task = Task::where('ical_uid', 'trip2')->sole();
        $task = $this->tasks->move($task, CarbonImmutable::parse('2026-11-01 10:00', 'Europe/Prague'), 90);

        $this->assertFalse($task->is_all_day);
        $this->assertSame('2026-11-01 09:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertStringContainsString('DTSTART:20261101T090000Z',
            $this->backend()->objectData($this->calendarId(), 'trip2.ics'));
    }

    public function test_an_event_with_no_dtend_gets_a_usable_block(): void
    {
        $this->backend()->createCalendarObject($this->calendarId(), 'nodtend.ics',
            $this->appleEvent('nodtend', ['DTEND' => null]));

        $task = Task::where('ical_uid', 'nodtend')->sole();

        $this->assertSame(30, (int) $task->starts_at->diffInMinutes($task->ends_at));
    }

    public function test_unscheduling_then_rescheduling_mints_a_fresh_uid_with_no_orphan(): void
    {
        $task = $this->tasks->create(['title' => 'Plan the trip']);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));
        $firstUid = $task->ical_uid;

        $task = $this->tasks->unschedule($task);
        $this->assertSame(Task::STATUS_INBOX, $task->status);
        $this->assertSame(0, $this->objectCount());

        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-30 11:00', 'Europe/Prague'));

        $this->assertNotSame($firstUid, $task->ical_uid);
        $this->assertSame(1, $this->objectCount());
        $this->assertSame(1, Task::count());
    }

    public function test_duplicating_one_of_our_events_on_a_device_produces_a_second_task(): void
    {
        $task = $this->tasks->create(['title' => 'Original']);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));

        // A copy carries our task hint but a new UID, the way a device copy would.
        $copy = str_replace(
            'UID:'.$task->ical_uid,
            'UID:device-copy-1',
            $this->backend()->objectData($this->calendarId(), $task->objectUri()),
        );
        $this->backend()->createCalendarObject($this->calendarId(), 'device-copy-1.ics', $copy);

        $this->assertSame(2, Task::count(), 'the copy must not hijack the original task');
        $this->assertSame($task->ical_uid, $task->refresh()->ical_uid);
        $this->assertNotNull(Task::where('ical_uid', 'device-copy-1')->first());
    }

    public function test_a_write_advances_the_sync_token(): void
    {
        $before = $this->syncToken();

        $this->backend()->createCalendarObject($this->calendarId(), 'apple-2.ics', $this->appleEvent('apple-2'));

        $this->assertGreaterThan($before, $this->syncToken());
    }

    public function test_an_unparseable_payload_is_rejected_as_unsupported_media_type(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->backend()->createCalendarObject($this->calendarId(), 'junk.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");
    }

    public function test_deleting_a_task_removes_its_calendar_object(): void
    {
        $task = $this->tasks->create(['title' => 'Throwaway']);
        $task = $this->tasks->schedule($task, CarbonImmutable::parse('2026-10-26 09:00', 'Europe/Prague'));

        $this->tasks->delete($task);

        $this->assertSame(0, Task::count());
        $this->assertSame(0, $this->objectCount());
    }
}
