<?php

namespace App\Http\Resources;

use App\Support\WallClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A bearer token, without its secret. Schema: `AccessToken` in openapi.yaml.
 *
 * Sanctum stores only a SHA-256 hash of the secret, so there is nothing here
 * that could show it again even if we wanted to.
 *
 * @mixin PersonalAccessToken
 */
class AccessTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'expired' => $this->expires_at !== null && $this->expires_at->isPast(),
            'expires_at' => WallClock::instant($this->expires_at),
            'last_used_at' => WallClock::instant($this->last_used_at),
            'created_at' => WallClock::instant($this->created_at),
        ];
    }
}
