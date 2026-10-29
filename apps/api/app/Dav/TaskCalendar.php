<?php

namespace App\Dav;

use App\Services\PlannerIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves the one calendar's identity. sabre addresses a calendar as
 * [calendarid, instanceid], so every call into the backend needs this pair.
 */
final class TaskCalendar
{
    /** @var array{0: int, 1: int}|null */
    private ?array $id = null;

    /** @return array{0: int, 1: int} */
    public function id(): array
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $row = DB::table('calendarinstances')
            ->where('principaluri', self::principalUri())
            ->where('uri', (string) config('caldav.calendar_uri'))
            ->first();

        if ($row === null) {
            throw new RuntimeException(
                'The CalDAV calendar has not been provisioned. Run: php artisan db:seed --class=DavSeeder'
            );
        }

        return $this->id = [(int) $row->calendarid, (int) $row->id];
    }

    public static function principalUri(): string
    {
        return PlannerIdentity::principalUri();
    }

    public function forget(): void
    {
        $this->id = null;
    }
}
