<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gap that let "planner:user doesn't work" go unnoticed: every other test
 * authenticates with actingAs() or a factory, so nothing ever signed in through
 * the form with a password the command itself had set.
 */
class WebLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_made_by_the_command_can_sign_in_through_the_form(): void
    {
        $this->artisan('planner:user', [
            '--email' => 'michal@skoula.com',
            '--password' => 'a-real-password',
        ])->assertSuccessful();

        $this->post('/login', [
            'email' => 'michal@skoula.com',
            'password' => 'a-real-password',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_the_command_stores_a_usable_hash_rather_than_a_double_hashed_one(): void
    {
        $this->artisan('planner:user', [
            '--email' => 'michal@skoula.com',
            '--password' => 'a-real-password',
        ])->assertSuccessful();

        $user = User::sole();

        $this->assertStringStartsWith('$2y$', $user->password);
        $this->assertTrue(auth()->validate([
            'email' => 'michal@skoula.com',
            'password' => 'a-real-password',
        ]));
    }

    public function test_running_the_command_twice_changes_the_password_instead_of_adding_a_user(): void
    {
        $this->artisan('planner:user', ['--email' => 'michal@skoula.com', '--password' => 'first-password'])
            ->assertSuccessful();
        $this->artisan('planner:user', ['--email' => 'michal@skoula.com', '--password' => 'second-password'])
            ->assertSuccessful();

        $this->assertSame(1, User::count());

        $this->post('/login', ['email' => 'michal@skoula.com', 'password' => 'first-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'michal@skoula.com', 'password' => 'second-password'])
            ->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_an_empty_password_is_refused_rather_than_stored(): void
    {
        $this->artisan('planner:user', ['--email' => 'michal@skoula.com', '--password' => ''])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_a_wrong_password_is_rejected_at_the_form(): void
    {
        $this->artisan('planner:user', ['--email' => 'michal@skoula.com', '--password' => 'the-right-one'])
            ->assertSuccessful();

        $this->post('/login', ['email' => 'michal@skoula.com', 'password' => 'the-wrong-one'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->artisan('planner:user', ['--email' => 'michal@skoula.com', '--password' => 'a-real-password'])
            ->assertSuccessful();

        $this->post('/login', ['email' => 'michal@skoula.com', 'password' => 'a-real-password']);
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
