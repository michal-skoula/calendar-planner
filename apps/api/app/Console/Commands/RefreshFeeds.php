<?php

namespace App\Console\Commands;

use App\Feeds\FeedFetcher;
use App\Feeds\FeedFetchResult;
use App\Models\CalendarFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-fetches subscribed calendars. Driven by planner-feeds.timer on the host,
 * the same way the backup is, because the container runs no cron and no queue
 * worker.
 *
 * The timer fires more often than any feed's own interval and this skips the
 * ones that are not due, so the schedule lives in the database rather than in a
 * systemd unit that would need editing to change it.
 */
class RefreshFeeds extends Command
{
    protected $signature = 'planner:feeds:refresh
                            {--feed= : Only this feed, by id or by name}
                            {--force : Fetch even feeds that are not due yet}';

    protected $description = 'Re-fetch subscribed calendars that are due';

    public function handle(FeedFetcher $fetcher): int
    {
        $feeds = $this->selected();

        if ($feeds->isEmpty()) {
            $this->line('No subscribed calendars to refresh.');

            return self::SUCCESS;
        }

        $attempted = 0;
        $failed = 0;

        foreach ($feeds as $feed) {
            if (! $this->option('force') && ! $feed->isStale()) {
                continue;
            }

            $attempted++;
            $result = $fetcher->fetch($feed);

            if ($result === FeedFetchResult::Failed) {
                $failed++;
            }

            $this->line(match ($result) {
                FeedFetchResult::Changed => "  {$feed->name}: updated, {$feed->series_count} series",
                FeedFetchResult::Unchanged => "  {$feed->name}: unchanged",
                FeedFetchResult::Failed => "  {$feed->name}: {$feed->last_error}",
            });
        }

        if ($attempted === 0) {
            $this->line('Nothing was due.');

            return self::SUCCESS;
        }

        // Non-zero only when every attempt failed. One provider having a bad
        // morning should not make the timer unit look broken.
        return $failed === $attempted ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, CalendarFeed> */
    private function selected(): Collection
    {
        $query = CalendarFeed::query()->enabled()->ordered();

        $only = $this->option('feed');

        if ($only !== null) {
            $query->where(fn ($q) => $q->where('id', $only)->orWhere('name', $only));
        }

        return $query->get();
    }
}
