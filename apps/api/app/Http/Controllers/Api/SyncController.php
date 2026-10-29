<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncClientResource;
use App\Http\Resources\SyncEventResource;
use App\Models\SyncClient;
use App\Models\SyncEvent;
use App\Services\PlannerRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What has been talking to this server, and what it changed.
 *
 * There is no push: CalDAV clients poll on their own schedule, so "when did it
 * last check in" is the only honest answer to "is it syncing". The tables
 * behind this are written by App\Services\SyncLog.
 */
class SyncController extends Controller
{
    /** GET /api/sync/clients — most recently seen first. */
    public function clients(): AnonymousResourceCollection
    {
        return SyncClientResource::collection(
            SyncClient::query()->orderByDesc('last_seen_at')->get()
        );
    }

    /** GET /api/sync/events — newest first. */
    public function events(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return SyncEventResource::collection(
            SyncEvent::query()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($data['limit'] ?? 100)
                ->get()
        );
    }

    /**
     * GET /api/revision
     *
     * Clients poll this and refetch only when it changes, which is how a task
     * moved on the phone reaches an open browser tab.
     */
    public function revision(): JsonResponse
    {
        return response()->json(['data' => ['revision' => PlannerRevision::current()]]);
    }
}
