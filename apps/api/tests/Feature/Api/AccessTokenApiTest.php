<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MatchesOpenApi;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * Bearer tokens: made and revoked from the web app, honoured everywhere else.
 */
class AccessTokenApiTest extends TestCase
{
    use MatchesOpenApi, ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_created_token_works_and_its_secret_is_shown_once(): void
    {
        $this->actingAs(User::sole());

        $secret = $this->postJson('/api/tokens', ['name' => 'iOS Shortcut'])
            ->assertCreated()
            ->assertJsonPath('data.access_token.name', 'iOS Shortcut')
            ->assertJsonPath('data.access_token.expires_at', null)
            ->json('data.token');

        $this->getJson('/api/tokens')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'iOS Shortcut')
            ->assertJsonMissingPath('data.0.token');

        // The stored value is a hash, not the secret.
        $this->assertStringNotContainsString(
            PersonalAccessToken::sole()->token,
            $secret,
        );

        auth()->forgetGuards();

        $this->withToken($secret)->getJson('/api/me')->assertOk();
    }

    public function test_an_expiry_date_lasts_through_the_end_of_that_day_in_prague(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 12:00:00');
        $this->actingAs(User::sole());

        $this->postJson('/api/tokens', ['name' => 'Short-lived', 'expires_on' => '2026-11-15'])
            ->assertCreated()
            // 23:59:59 on 15 November in Prague, which is CET, so 22:59:59 UTC.
            ->assertJsonPath('data.access_token.expires_at', '2026-11-15T22:59:59Z')
            ->assertJsonPath('data.access_token.expired', false);
    }

    public function test_an_expired_token_is_refused(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 12:00:00');
        $secret = User::sole()->createToken('Old', ['*'], CarbonImmutable::parse('2026-10-28 00:00:00'))->plainTextToken;

        $this->withToken($secret)->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_an_expiry_in_the_past_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 12:00:00');
        $this->actingAs(User::sole());

        $this->postJson('/api/tokens', ['name' => 'Backdated', 'expires_on' => '2026-10-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_on');
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs(User::sole());

        $this->postJson('/api/tokens', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $new = User::sole()->createToken('Laptop');

        $this->actingAs(User::sole());
        $this->deleteJson("/api/tokens/{$new->accessToken->id}")->assertNoContent();
        auth()->forgetGuards();

        $this->withToken($new->plainTextToken)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_revoking_an_unknown_token_is_a_404(): void
    {
        $this->actingAs(User::sole());

        $this->deleteJson('/api/tokens/999')->assertNotFound();
    }

    public function test_a_token_cannot_manage_tokens(): void
    {
        $secret = User::sole()->createToken('Leaked')->plainTextToken;

        $this->withToken($secret)->getJson('/api/tokens')->assertForbidden();
        $this->withToken($secret)->postJson('/api/tokens', ['name' => 'Replacement'])->assertForbidden();

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_the_command_can_set_an_expiry(): void
    {
        CarbonImmutable::setTestNow('2026-10-29 12:00:00');

        $this->artisan('planner:token', ['name' => 'cli', '--expires' => '2026-12-31'])->assertSuccessful();
        $this->artisan('planner:token', ['name' => 'bad', '--expires' => '2026-01-01'])->assertFailed();

        $this->assertSame('2026-12-31 22:59:59', PersonalAccessToken::sole()->expires_at->utc()->toDateTimeString());
    }
}
