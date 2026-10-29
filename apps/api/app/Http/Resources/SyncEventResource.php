<?php

namespace App\Http\Resources;

use App\Models\SyncEvent;
use App\Support\WallClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of sync activity. Schema: `SyncEvent`.
 *
 * `sentence` is phrased on the server so every client reads the same words.
 *
 * @mixin SyncEvent
 */
class SyncEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source' => $this->source,
            'client' => $this->client,
            'task_id' => $this->task_id,
            'title' => $this->title,
            'detail' => $this->detail,
            'sentence' => $this->sentence(),
            'failure' => $this->isFailure(),
            'occurred_at' => WallClock::instant($this->occurred_at),
        ];
    }
}
