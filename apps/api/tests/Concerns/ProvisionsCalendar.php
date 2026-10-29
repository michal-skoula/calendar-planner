<?php

namespace Tests\Concerns;

use App\Dav\TaskCalendar;
use App\Dav\TaskCalendarBackend;
use App\Models\User;
use Database\Seeders\DavSeeder;
use Illuminate\Support\Facades\DB;

trait ProvisionsCalendar
{
    protected string $calDavPassword = 'correct-horse-battery';

    protected string $calDavEmail = 'michal@skoula.com';

    /**
     * The account and the calendar. CalDAV authenticates against the planner
     * account itself, so provisioning starts with the user row the principal is
     * then derived from.
     */
    protected function provisionCalendar(): void
    {
        config([
            'caldav.realm' => 'Tasks',
            'caldav.done_prefix' => '✅ ',
            'caldav.display_timezone' => 'Europe/Prague',
            'caldav.default_duration_min' => 30,
        ]);

        User::query()->create([
            'name' => 'Michal',
            'email' => $this->calDavEmail,
            'password' => $this->calDavPassword,
        ]);

        $this->seed(DavSeeder::class);
        app(TaskCalendar::class)->forget();
    }

    /** The calendar home, as sabre writes it into an href. */
    protected function calendarHome(string $suffix = ''): string
    {
        return '/dav/calendars/'.\Sabre\HTTP\encodePath($this->calDavEmail).$suffix;
    }

    protected function principalPath(string $suffix = ''): string
    {
        return '/dav/principals/'.\Sabre\HTTP\encodePath($this->calDavEmail).$suffix;
    }

    /** @return array{0: int, 1: int} */
    protected function calendarId(): array
    {
        return app(TaskCalendar::class)->id();
    }

    protected function backend(): TaskCalendarBackend
    {
        return app(TaskCalendarBackend::class);
    }

    protected function objectCount(): int
    {
        return DB::table('calendarobjects')->count();
    }

    protected function syncToken(): int
    {
        return (int) DB::table('calendars')->value('synctoken');
    }

    /**
     * An iCalendar document shaped the way Apple sends them.
     *
     * @param  array<string, string|null>  $overrides
     */
    protected function appleEvent(string $uid, array $overrides = []): string
    {
        $lines = array_merge([
            'SUMMARY' => 'Call the dentist',
            'DTSTART' => '20261029T080000Z',
            'DTEND' => '20261029T090000Z',
            'DTSTAMP' => '20261023T120000Z',
        ], $overrides);

        $body = '';
        foreach ($lines as $name => $value) {
            if ($value === null) {
                continue;
            }
            $body .= $name.':'.$value."\r\n";
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Apple Inc.//iPhone OS 26.1//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:{$uid}\r\n".$body."END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /** @return array<string, string> */
    protected function basicAuth(?string $password = null, ?string $username = null): array
    {
        $credentials = ($username ?? $this->calDavEmail).':'.($password ?? $this->calDavPassword);

        return ['Authorization' => 'Basic '.base64_encode($credentials)];
    }
}
