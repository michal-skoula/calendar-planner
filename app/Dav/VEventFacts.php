<?php

namespace App\Dav;

use Carbon\CarbonImmutable;

/**
 * Everything this application cares about from one VEVENT, already normalised.
 * Times are always UTC. For an all-day event `endsAt` is the exclusive end, as
 * iCalendar's DTEND is for DATE values.
 */
final readonly class VEventFacts
{
    /** @param array<int, string> $tags */
    public function __construct(
        public string $uid,
        public string $title,
        public ?string $notes,
        public array $tags,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $isAllDay,
        public bool $isDone,
        public ?int $taskId = null,
    ) {}
}
