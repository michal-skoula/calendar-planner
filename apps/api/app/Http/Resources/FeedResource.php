<?php

namespace App\Http\Resources;

use App\Models\CalendarFeed;
use App\Support\WallClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscribed calendar. Schema: `Feed` in openapi.yaml.
 *
 * The URL is deliberately absent. A Google or iCloud feed URL is a bearer
 * credential, which is why it is stored encrypted, and no client needs it back.
 *
 * @mixin CalendarFeed
 */
class FeedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'enabled' => $this->is_enabled,
            'healthy' => $this->isHealthy(),
            'status_line' => $this->statusLine(),
            'refresh_min' => $this->refresh_min,
            'series_count' => $this->series_count,
            'dropped_count' => $this->dropped_count,
            'payload_bytes' => $this->payload_bytes,
            'last_status' => $this->last_status,
            'last_error' => $this->last_error,
            'last_fetched_at' => WallClock::instant($this->last_fetched_at),
            'last_success_at' => WallClock::instant($this->last_success_at),
        ];
    }
}
