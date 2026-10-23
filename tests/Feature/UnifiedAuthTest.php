<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * One account, one password, both doors.
 *
 * The planner used to keep a web user in the database and a CalDAV app password
 * in the environment, which meant two secrets, two usernames, and no way to tell
 * a wrong password from a principal that owned no calendar. These tests pin the
 * single credential down from both sides.
 */
class UnifiedAuthTest extends TestCase
{
    use ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
    }

    public function test_the_one_credential_opens_both_the_web_ui_and_caldav(): void
    {
        $this->post('/login', [
            'email' => $this->calDavEmail,
            'password' => $this->calDavPassword,
        ])->assertRedirect('/');
        $this->assertAuthenticated();

        $this->propfind()->assertStatus(207);
    }

    public function test_changing_the_password_changes_both_surfaces_at_once(): void
    {
        $this->artisan('planner:user', [
            '--email' => $this->calDavEmail,
            '--password' => 'a-brand-new-password',
        ])->assertSuccessful();

        $this->propfind($this->basicAuth())->assertStatus(401);
        $this->propfind($this->basicAuth('a-brand-new-password'))->assertStatus(207);

        $this->post('/login', ['email' => $this->calDavEmail, 'password' => $this->calDavPassword])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $this->calDavEmail, 'password' => 'a-brand-new-password'])
            ->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_the_command_refuses_to_leave_a_second_account_behind(): void
    {
        User::query()->create([
            'name' => 'Stray',
            'email' => 'michalskoula5@gmail.com',
            'password' => 'whatever-it-was',
        ]);

        $this->artisan('planner:user', [
            '--email' => $this->calDavEmail,
            '--password' => 'a-brand-new-password',
        ])->assertFailed();

        $this->assertSame(2, User::count());
    }

    public function test_replace_collapses_the_extra_accounts(): void
    {
        $stray = User::query()->create([
            'name' => 'Stray',
            'email' => 'michalskoula5@gmail.com',
            'password' => 'whatever-it-was',
        ]);

        $this->artisan('planner:user', [
            '--email' => $this->calDavEmail,
            '--password' => 'a-brand-new-password',
            '--replace' => true,
        ])->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertNull(User::find($stray->getKey()));
        $this->propfind($this->basicAuth('a-brand-new-password'))->assertStatus(207);
    }

    public function test_a_stray_second_account_cannot_sign_in_anywhere(): void
    {
        User::query()->create([
            'name' => 'Stray',
            'email' => 'michalskoula5@gmail.com',
            'password' => 'a-real-password',
        ]);

        $this->post('/login', ['email' => 'michalskoula5@gmail.com', 'password' => 'a-real-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->propfind($this->basicAuth('a-real-password', 'michalskoula5@gmail.com'))
            ->assertStatus(401);
    }

    public function test_renaming_the_account_carries_the_calendar_over(): void
    {
        $this->backend()->createCalendarObject($this->calendarId(), 'keep-me.ics', $this->appleEvent('keep-me'));
        $objects = $this->objectCount();

        $this->artisan('planner:user', [
            '--email' => 'michal@example.test',
            '--password' => 'a-brand-new-password',
        ])->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(['principals/michal@example.test'], DB::table('principals')->pluck('uri')->all());
        $this->assertSame(
            'principals/michal@example.test',
            DB::table('calendarinstances')->value('principaluri'),
        );

        // The objects and the sync token survive the move; only the URL changed.
        $this->assertSame($objects, $this->objectCount());

        $this->calDavEmail = 'michal@example.test';
        $home = $this->propfind($this->basicAuth('a-brand-new-password'), $this->calendarHome('/tasks'), 1);
        $home->assertStatus(207);
        $this->assertStringContainsString('keep-me.ics', $home->getContent());
    }

    /** @param  array<string, string>|null  $auth */
    private function propfind(?array $auth = null, string $uri = '/dav', int $depth = 0): TestResponse
    {
        $headers = ($auth ?? $this->basicAuth()) + [
            'Depth' => (string) $depth,
            'Content-Type' => 'application/xml',
        ];

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('PROPFIND', $uri, server: $server,
            content: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>');
    }
}
