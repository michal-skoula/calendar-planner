<?php

namespace App\Feeds;

use Carbon\CarbonImmutable;

/**
 * One rectangle on the grid: a single occurrence of a single feed event.
 *
 * Times are UTC, and for an all-day occurrence `endsAt` is the exclusive end, as
 * iCalendar's DTEND is for DATE values and as `tasks.ends_at` already is. That
 * agreement is what lets this reach a calendar widget without a day's
 * arithmetic anywhere in between.
 *
 * A feed occurrence is never persisted. App\Feeds\FeedExpander builds the ones a
 * visible window needs and they are thrown away afterwards, which is the whole
 * reason subscriptions need no occurrences table while tasks would.
 */
final readonly class FeedOccurrence
{
    public function __construct(
        public int $feedId,
        public string $uid,
        public ?string $recurrenceId,
        public string $title,
        public ?string $location,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $isAllDay,
        public bool $isBusy,
    ) {}

    /**
     * The id clients see.
     *
     * Never a bare integer. Task ids are integers and share the same calendar
     * grid, so a numeric feed id is one careless client away from completing
     * whichever task happened to share that number.
     */
    public function eventId(): string
    {
        return 'feed-'.$this->feedId.'-'.substr(hash('sha256', $this->uid), 0, 12)
            .($this->recurrenceId === null ? '' : '-'.$this->recurrenceId);
    }
}
