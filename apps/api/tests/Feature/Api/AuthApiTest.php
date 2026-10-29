<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\MatchesOpenApi;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * The two ways in: a session for the web app, a bearer token for everything
 * else. Session sign-in through the form is covered in WebLoginTest.
 */
class AuthApiTest extends TestCase
{
    use MatchesOpenApi, ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
    }

    public function test_a_native_client_trades_the_password_for_a_working_token(): void
    {
        $token = $this->postJson('/api/auth/tokens', [
            'email' => 'Michal@Skoula.com',
            'password' => $this->calDavPassword,
            'device_name' => 'Desktop',
        ])->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.email', $this->calDavEmail);
    }

    public function test_a_wrong_password_gets_no_token(): void
    {
        $this->postJson('/api/auth/tokens', [
            'email' => $this->calDavEmail,
            'password' => 'nope',
            'device_name' => 'Desktop',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_signing_out_with_a_token_revokes_it(): void
    {
        $token = User::sole()->createToken('Desktop')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_session_sign_in_is_refused_without_a_browser_origin(): void
    {
        // No Referer or Origin from a stateful domain, so Sanctum starts no
        // session and there is nowhere to keep one.
        $this->postJson('/api/auth/login', ['email' => $this->calDavEmail, 'password' => $this->calDavPassword])
            ->assertStatus(400);
    }

    public function test_sign_in_is_rate_limited(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->signIn($this->calDavEmail, 'wrong')->assertUnprocessable();
        }

        $this->signIn($this->calDavEmail, $this->calDavPassword)->assertTooManyRequests();
    }
}
