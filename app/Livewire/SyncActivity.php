<?php

namespace App\Livewire;

use App\Models\SyncClient;
use App\Models\SyncEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What has been talking to this server, and what it changed.
 *
 * There is no push: clients poll on their own schedule, so "when did it last
 * check in" is the only honest answer to "is it syncing", and it is the first
 * thing on the page.
 */
#[Layout('components.layouts.app')]
#[Title('Sync')]
class SyncActivity extends Component
{
    private const SHOW_EVENTS = 100;

    public function render(): View
    {
        return view('livewire.sync-activity', [
            'clients' => SyncClient::query()->orderByDesc('last_seen_at')->get(),
            'events' => $this->events(),
        ]);
    }

    /** @return Collection<int, SyncEvent> */
    private function events(): Collection
    {
        return SyncEvent::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::SHOW_EVENTS)
            ->get();
    }
}
