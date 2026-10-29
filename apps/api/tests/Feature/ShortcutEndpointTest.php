<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MatchesOpenApi;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * POST /api/tasks as the iOS Shortcut uses it: a bearer token and a flat JSON
 * body. The general task API is covered in Api\TaskApiTest.
 */
class ShortcutEndpointTest extends TestCase
{
    use MatchesOpenApi, ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
    }

    public function test_a_post_without_a_start_lands_in_the_inbox(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/tasks', [
            'title' => 'Install mole',
            'duration_min' => 20,
            'tags' => ['tools'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Task::STATUS_INBOX)
            ->assertJsonPath('data.start', null)
            ->assertJsonPath('data.tags', ['tools']);

        $this->assertSame(0, $this->objectCount());
    }

    public function test_a_post_with_a_start_schedules_the_task(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/tasks', [
            'title' => 'Standup registration',
            'start' => '2026-10-29T09:00:00+01:00',
            'duration_min' => 60,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', Task::STATUS_SCHEDULED);

        $task = Task::sole();
        $this->assertSame('2026-10-29 08:00:00', $task->starts_at->utc()->toDateTimeString());
        $this->assertSame(1, $this->objectCount());
    }

    public function test_a_naive_start_is_read_as_local_wall_clock(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tasks', [
            'title' => 'No offset given',
            'start' => '2026-10-29T09:00:00',
        ])->assertCreated();

        // 09:00 in Europe/Prague is 08:00 UTC, not 09:00 UTC.
        $this->assertSame('2026-10-29 08:00:00', Task::sole()->starts_at->utc()->toDateTimeString());
    }

    public function test_a_deadline_is_accepted_and_kept_separate_from_the_start(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tasks', [
            'title' => 'Export checkout copy',
            'deadline' => '2026-11-15',
        ])->assertCreated()->assertJsonPath('data.deadline', '2026-11-15');

        $this->assertNull(Task::sole()->starts_at);
    }

    public function test_a_missing_token_is_rejected(): void
    {
        $this->postJson('/api/tasks', ['title' => 'Nope'])->assertUnauthorized();

        $this->assertSame(0, Task::count());
    }

    public function test_a_title_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tasks', ['notes' => 'no title'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }
}
