<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A CalDAV client that has talked to this server, and when it last did.
 *
 * Identity is the shortened User-Agent, so a phone and a Mac are separate rows
 * while the same device staying on the same OS version stays one.
 */
class SyncClient extends Model
{
    protected $fillable = ['agent', 'last_seen_at', 'last_method', 'last_status', 'request_count'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'last_status' => 'integer',
            'request_count' => 'integer',
        ];
    }

    public function isHealthy(): bool
    {
        return $this->last_status !== null && $this->last_status < 400;
    }
}
