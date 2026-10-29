<?php

namespace App\Feeds;

/**
 * What one expansion produced, including what it had to give up on.
 *
 * `dropped` is returned rather than logged from inside the expander so the
 * caller decides what a lost series means: the fetcher records one sync event
 * per series, the render path ignores it and draws what it has.
 */
final readonly class FeedExpansion
{
    /**
     * @param  array<int, FeedOccurrence>  $occurrences
     * @param  array<string, string>  $dropped  UID => why it could not be walked
     */
    public function __construct(
        public array $occurrences,
        public int $seriesSeen,
        public array $dropped,
    ) {}

    public static function empty(): self
    {
        return new self([], 0, []);
    }
}
