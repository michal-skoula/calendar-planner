<div id="planner" wire:poll.20s="heartbeat" class="flex h-full flex-col lg:h-screen lg:flex-row">

    {{-- Inbox. Also the drop target that unschedules an event dragged back out. --}}
    <aside data-inbox
           class="flex w-full shrink-0 flex-col border-b border-stone-200 bg-white lg:w-80 lg:border-b-0 lg:border-r dark:border-stone-800 dark:bg-stone-900">

        <div class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-stone-800">
            <h1 class="text-sm font-semibold tracking-tight">Inbox</h1>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-xs text-stone-400 hover:text-stone-700 dark:hover:text-stone-200">Sign out</button>
            </form>
        </div>

        <form wire:submit="addTask" class="space-y-2 border-b border-stone-200 px-4 py-3 dark:border-stone-800">
            <input type="text" wire:model="newTitle" placeholder="What needs doing?" autocomplete="off"
                   class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-1 focus:ring-teal-600 dark:border-stone-700 dark:bg-stone-950">
            <div class="flex gap-2">
                <input type="number" wire:model="newDuration" placeholder="min" min="5" max="1440" step="5"
                       class="w-20 rounded-md border border-stone-300 bg-white px-2 py-1.5 text-sm tabular-nums outline-none focus:border-teal-600 dark:border-stone-700 dark:bg-stone-950">
                <button type="submit"
                        class="flex-1 rounded-md bg-teal-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-800">
                    Add
                </button>
            </div>
            @error('newTitle') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @error('newDuration') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        </form>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            @forelse ($inbox as $task)
                <div data-task-id="{{ $task->id }}"
                     data-title="{{ $task->title }}"
                     data-duration="{{ $task->plannedDuration() }}"
                     wire:key="inbox-{{ $task->id }}"
                     class="group mb-2 cursor-grab rounded-md border border-stone-200 bg-white p-2.5 shadow-sm transition hover:border-teal-600 active:cursor-grabbing dark:border-stone-800 dark:bg-stone-950">
                    <div class="flex items-start gap-2">
                        <button type="button" wire:click="toggleComplete({{ $task->id }})"
                                title="Mark done"
                                class="mt-0.5 size-4 shrink-0 rounded border border-stone-300 hover:border-teal-600 hover:bg-teal-50 dark:border-stone-700 dark:hover:bg-teal-950"></button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $task->title }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-stone-500">
                                <span class="tabular-nums">{{ $task->plannedDuration() }}m</span>
                                @if ($task->deadline)
                                    <span @class([
                                        'rounded px-1.5 py-0.5 font-medium tabular-nums',
                                        'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $task->deadline->isPast(),
                                        'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300' => ! $task->deadline->isPast(),
                                    ])>due {{ $task->deadline->format('j M') }}</span>
                                @endif
                                @foreach ($task->tags as $tag)
                                    <span class="rounded bg-stone-100 px-1.5 py-0.5 dark:bg-stone-800">{{ $tag }}</span>
                                @endforeach
                            </div>
                        </div>
                        <button type="button" wire:click="deleteTask({{ $task->id }})"
                                wire:confirm="Delete “{{ $task->title }}”?"
                                class="opacity-0 transition group-hover:opacity-100 text-xs text-stone-400 hover:text-red-600">&times;</button>
                    </div>
                </div>
            @empty
                <p class="px-1 py-6 text-center text-sm text-stone-400">Inbox is empty.</p>
            @endforelse
        </div>

        @if ($doneToday->isNotEmpty())
            <div class="border-t border-stone-200 p-3 dark:border-stone-800">
                <p class="mb-2 px-1 text-xs font-medium uppercase tracking-wider text-stone-400">Done today</p>
                @foreach ($doneToday as $task)
                    <button type="button" wire:click="toggleComplete({{ $task->id }})"
                            wire:key="done-{{ $task->id }}"
                            title="Reopen"
                            class="mb-1 block w-full truncate rounded px-1 py-0.5 text-left text-xs text-stone-400 line-through hover:bg-stone-100 dark:hover:bg-stone-800">
                        {{ $task->title }}
                    </button>
                @endforeach
            </div>
        @endif
    </aside>

    {{-- The calendar. wire:ignore keeps Livewire out of FullCalendar's DOM, so the
         20-second poll above can never disturb a drag in progress. --}}
    <main class="min-h-[70vh] flex-1 p-3 lg:min-h-0">
        <div wire:ignore data-calendar class="h-full"></div>
    </main>

    @script
    <script>
        const calendar = window.bootPlanner(document.getElementById('planner'), $wire, {
            locale: @js(str_replace('_', '-', app()->getLocale())),
            defaultDuration: @js((int) config('caldav.default_duration_min')),
        });

        $wire.on('calendar-stale', () => calendar.refetchEvents());
    </script>
    @endscript
</div>
