<?php

return [

    /*
    | How often a feed is re-fetched, in minutes, when it does not say otherwise.
    | The timer on the box fires more often than this and skips any feed that is
    | still inside its own interval, so lowering this costs nothing at the host.
    */
    'refresh_min' => (int) env('PLANNER_FEED_REFRESH_MIN', 60),

    /*
    | Connect and total timeout, in seconds. Short on purpose: nothing waits on
    | this. The browser is reading the last payload that parsed either way.
    */
    'timeout' => (int) env('PLANNER_FEED_TIMEOUT', 10),

    /*
    | Hard ceiling on a response body. Enforced while streaming, so an endless
    | response is cut off rather than buffered. Every byte under this ends up in
    | the SQLite file and therefore in the nightly backup tarball.
    */
    'max_bytes' => (int) env('PLANNER_FEED_MAX_BYTES', 2 * 1024 * 1024),

    /*
    | Local development only. With this on, http:// and private addresses are
    | allowed, which on the box would mean a pasted URL could reach Caddy, n8n
    | or any other service on the compose bridge.
    */
    'allow_private' => (bool) env('PLANNER_FEED_ALLOW_PRIVATE', false),

    /*
    | Days of slack either side of the window the browser asks for. FullCalendar
    | already pads its range; this covers an event that starts just outside it.
    */
    'window_pad_days' => 2,

    /*
    | Expansions are keyed on the payload hash, so a stale entry is unreachable
    | rather than wrong. This only stops the cache growing forever.
    */
    'cache_ttl' => (int) env('PLANNER_FEED_CACHE_TTL', 86400),

];
