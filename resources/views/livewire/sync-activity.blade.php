<div wire:poll.15s class="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-6 px-4 py-8">

    <div class="flex items-baseline justify-between gap-3">
        <h1 class="text-lg font-semibold tracking-tight">Sync</h1>
        <a href="{{ route('planner') }}" wire:navigate
           class="text-xs text-stone-500 hover:text-stone-900 dark:hover:text-stone-100">&larr; Planner</a>
    </div>

    {{-- Who is talking to this server. There is no push, so "last checked" is
         the only honest answer to "is it syncing". --}}
    <section class="space-y-2">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-stone-400">Clients</h2>

        @forelse ($clients as $client)
            <div class="flex items-center gap-3 rounded-md border border-stone-200 bg-white px-3 py-2.5 dark:border-stone-800 dark:bg-stone-900">
                <span @class([
                    'size-2 shrink-0 rounded-full',
                    'bg-teal-600' => $client->isHealthy(),
                    'bg-red-500' => ! $client->isHealthy(),
                ])></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ $client->agent }}</p>
                    <p class="text-xs text-stone-500">
                        last checked {{ $client->last_seen_at->diffForHumans() }}
                        &middot; {{ $client->last_method }} answered {{ $client->last_status }}
                    </p>
                </div>
                <span class="shrink-0 text-xs tabular-nums text-stone-400">{{ number_format($client->request_count) }} reqs</span>
            </div>
        @empty
            <p class="rounded-md border border-dashed border-stone-300 px-3 py-6 text-center text-sm text-stone-500 dark:border-stone-700">
                Nothing has connected over CalDAV yet.
            </p>
        @endforelse
    </section>

    <section class="space-y-2">
        <h2 class="text-xs font-semibold uppercase tracking-wide text-stone-400">Activity</h2>

        @forelse ($events as $event)
            <div wire:key="event-{{ $event->id }}"
                 @class([
                    'flex items-baseline gap-3 rounded-md border px-3 py-2 text-sm',
                    'border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900' => ! $event->isFailure(),
                    'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40' => $event->isFailure(),
                 ])>
                <span class="shrink-0 text-xs tabular-nums text-stone-400"
                      title="{{ $event->occurred_at->timezone(config('caldav.display_timezone'))->toDayDateTimeString() }}">
                    {{ $event->occurred_at->timezone(config('caldav.display_timezone'))->format('H:i') }}
                </span>
                <p class="min-w-0 flex-1">
                    @if ($event->title)
                        <span class="font-medium">{{ $event->title }}</span>
                    @endif
                    <span @class(['text-stone-500' => ! $event->isFailure(), 'text-red-700 dark:text-red-300' => $event->isFailure()])>
                        {{ $event->sentence() }}
                    </span>
                    @if ($event->detail)
                        <span class="block text-xs text-stone-500">{{ $event->detail }}</span>
                    @endif
                </p>
                <span class="shrink-0 text-xs text-stone-400">{{ $event->occurred_at->diffForHumans(short: true) }}</span>
            </div>
        @empty
            <p class="rounded-md border border-dashed border-stone-300 px-3 py-6 text-center text-sm text-stone-500 dark:border-stone-700">
                Nothing has changed yet.
            </p>
        @endforelse
    </section>

    <p class="text-xs text-stone-400">
        Clients poll on their own schedule — there is no push. On macOS that is
        Calendar &rsaquo; Settings &rsaquo; Accounts &rsaquo; Refresh Calendars; on iOS, Settings &rsaquo; Apps &rsaquo;
        Calendar &rsaquo; Accounts &rsaquo; Fetch New Data.
    </p>
</div>
