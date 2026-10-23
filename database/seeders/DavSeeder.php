<?php

namespace Database\Seeders;

use App\Dav\TaskCalendar;
use App\Dav\TaskCalendarBackend;
use App\Services\PlannerIdentity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

/**
 * Provisions the single DAV principal and the single calendar. Idempotent, so it
 * is safe to run on every deploy.
 *
 * The principal is derived from the planner account, so changing the account's
 * email moves the principal — and the calendar with it. The calendar itself is
 * created through the backend rather than by inserting rows, so its instance row
 * and sync token start out consistent.
 */
class DavSeeder extends Seeder
{
    /** Every DAV table that addresses a principal by URI rather than by id. */
    private const PRINCIPAL_REFS = ['calendarinstances', 'calendarsubscriptions', 'schedulingobjects'];

    public function run(): void
    {
        $user = PlannerIdentity::userOrFail();
        $principalUri = PlannerIdentity::principalUri();
        $calendarUri = (string) config('caldav.calendar_uri');

        DB::transaction(function () use ($user, $principalUri): void {
            $this->repoint($principalUri);

            DB::table('principals')->updateOrInsert(
                ['uri' => $principalUri],
                [
                    'email' => $user->email,
                    'displayname' => $user->name ?: $user->email,
                ],
            );
        });

        app(TaskCalendar::class)->forget();

        $exists = DB::table('calendarinstances')
            ->where('principaluri', $principalUri)
            ->where('uri', $calendarUri)
            ->exists();

        if ($exists) {
            $this->command?->info("CalDAV calendar '{$calendarUri}' already provisioned for {$principalUri}.");

            return;
        }

        app(TaskCalendarBackend::class)->createCalendar($principalUri, $calendarUri, [
            '{DAV:}displayname' => (string) config('caldav.calendar_name'),
            '{http://apple.com/ns/ical/}calendar-color' => (string) config('caldav.calendar_color'),
            // VEVENT only. Apple will not attempt to store VTODO or VJOURNAL here,
            // which keeps the one-task-per-VEVENT model honest.
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
        ]);

        app(TaskCalendar::class)->forget();

        $this->command?->info("CalDAV calendar '{$calendarUri}' created for {$principalUri}.");
    }

    /**
     * Carry an existing principal over to the account's current address.
     *
     * The principal URI used to come from a config value and now comes from the
     * account's email, so an already-provisioned install has a calendar hanging
     * off the old name. Moving the rows keeps its objects and sync token rather
     * than stranding them under a principal nothing authenticates as.
     */
    private function repoint(string $principalUri): void
    {
        if (DB::table('principals')->where('uri', $principalUri)->exists()) {
            return;
        }

        $existing = DB::table('principals')->pluck('uri');

        // Nothing to carry over, or more than one candidate and therefore no
        // single obvious answer. Either way, leave the rows alone.
        if ($existing->count() !== 1) {
            return;
        }

        $from = (string) $existing->first();

        DB::table('principals')->where('uri', $from)->update(['uri' => $principalUri]);

        foreach (self::PRINCIPAL_REFS as $table) {
            DB::table($table)->where('principaluri', $from)->update(['principaluri' => $principalUri]);
        }

        $this->command?->warn("Moved the DAV principal {$from} -> {$principalUri}.");
        $this->command?->warn('The account URL has changed, so any device already syncing must re-add it.');
    }
}
