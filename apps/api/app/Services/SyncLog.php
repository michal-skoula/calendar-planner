<?php

namespace App\Services;

use App\Models\SyncClient;
use App\Models\SyncEvent;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what the sync page shows.
 *
 * Writes reach TaskCalendarBackend from two directions — a device over HTTP, and
 * our own TaskService — through the same methods. The difference is knowable
 * only from context, so DavLogPlugin names the client at the start of each DAV
 * request and clears it at the end; no client means our own code is writing.
 *
 * Nothing here may ever break a sync. Every write is wrapped: a failure to
 * record an event is worth a log line and nothing more.
 */
final class SyncLog
{
    /** Events older than this are pruned; the page shows far less than this anyway. */
    private const RETAIN_DAYS = 30;

    private ?string $client = null;

    public function fromClient(?string $client): void
    {
        $this->client = $client === '' ? null : $client;
    }

    public function client(): ?string
    {
        return $this->client;
    }

    public function source(): string
    {
        return $this->client !== null ? SyncEvent::SOURCE_CALDAV : SyncEvent::SOURCE_PLANNER;
    }

    /**
     * $source overrides what this write is attributed to. A feed refresh runs
     * with no DAV client in scope and is not the planner either, so without it
     * every fetch would be recorded as a change someone made in the browser.
     */
    public function record(
        string $kind,
        ?Task $task = null,
        ?string $title = null,
        ?string $detail = null,
        ?string $source = null,
    ): void {
        $this->safely(function () use ($kind, $task, $title, $detail, $source): void {
            $event = SyncEvent::query()->create([
                'kind' => $kind,
                'source' => $source ?? $this->source(),
                'client' => $this->client,
                'task_id' => $task?->getKey(),
                'title' => $title ?? $task?->title,
                'detail' => $detail,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            // Pruning on a fixed multiple rather than at random keeps the test
            // suite deterministic while still being rare enough to be free.
            if ($event->getKey() % 50 === 0) {
                SyncEvent::query()
                    ->where('occurred_at', '<', CarbonImmutable::now()->subDays(self::RETAIN_DAYS))
                    ->delete();
            }
        });
    }

    /** A client checked in. Upserted, so this row count never grows with traffic. */
    public function seen(string $client, string $method, int $status): void
    {
        $this->safely(function () use ($client, $method, $status): void {
            $row = SyncClient::query()->firstOrNew(['agent' => $client]);
            $row->last_seen_at = CarbonImmutable::now();
            $row->last_method = $method;
            $row->last_status = $status;
            $row->request_count = $row->request_count + 1;
            $row->save();
        });
    }

    private function safely(callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            // Before the migration has run, or if the table is gone, syncing must
            // still work. This is bookkeeping, not the job.
            Log::channel('dav')->warning('Could not record a sync event: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
