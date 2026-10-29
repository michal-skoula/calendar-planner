<?php

namespace App\Http\Resources;

use App\Models\SyncClient;
use App\Support\WallClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A CalDAV client that has talked to this server. Schema: `SyncClient`.
 *
 * @mixin SyncClient
 */
class SyncClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent,
            'healthy' => $this->isHealthy(),
            'last_seen_at' => WallClock::instant($this->last_seen_at),
            'last_method' => $this->last_method,
            'last_status' => $this->last_status,
            'request_count' => $this->request_count,
        ];
    }
}
