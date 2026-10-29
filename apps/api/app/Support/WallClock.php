<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The planner's two time conventions, in one place.
 *
 * Planning times (when a task starts and ends) cross the API as naive wall clock
 * in the configured display timezone, with no offset: `2026-10-26T09:00:00`.
 * That way every client draws Prague time whatever its own machine is set to,
 * and a value read from one endpoint can be sent back to another unchanged. The
 * MCP tools use the same convention.
 *
 * Things that happened (a task was completed, a device checked in) are instants,
 * and go out as UTC with a `Z`.
 *
 * Storage is always UTC. Nothing here touches the database.
 */
final class WallClock
{
    public static function zone(): string
    {
        return (string) config('caldav.display_timezone');
    }

    /**
     * Parse a client-supplied time. A naive string is wall clock in the display
     * timezone; one carrying its own offset is honoured as given.
     */
    public static function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::zone());
    }

    /** UTC instant to naive local wall clock, e.g. 2026-10-26T09:00:00. */
    public static function local(?DateTimeInterface $at): ?string
    {
        return $at === null
            ? null
            : CarbonImmutable::instance($at)->setTimezone(self::zone())->format('Y-m-d\TH:i:s');
    }

    /** An instant as RFC 3339 UTC, e.g. 2026-10-26T08:00:00Z. */
    public static function instant(?DateTimeInterface $at): ?string
    {
        return $at === null
            ? null
            : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
