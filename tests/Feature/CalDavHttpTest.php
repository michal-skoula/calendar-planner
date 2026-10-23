<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ProvisionsCalendar;
use Tests\TestCase;

/**
 * The surface Apple Calendar actually talks to: the Basic challenge, the discovery
 * chain, and a real PUT and DELETE over HTTP.
 */
class CalDavHttpTest extends TestCase
{
    use ProvisionsCalendar, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionCalendar();
    }

    public function test_an_unauthenticated_request_is_challenged_with_basic_not_bearer(): void
    {
        $response = $this->call('PROPFIND', '/dav', server: ['HTTP_DEPTH' => '0']);

        $response->assertStatus(401);

        $challenge = $response->headers->get('WWW-Authenticate');
        $this->assertStringStartsWith('Basic', (string) $challenge);
        $this->assertStringContainsString('realm="Tasks"', (string) $challenge);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->call('PROPFIND', '/dav', server: $this->serverHeaders($this->basicAuth('wrong')))
            ->assertStatus(401);
    }

    public function test_an_unknown_address_is_rejected(): void
    {
        $this->call('PROPFIND', '/dav', server: $this->serverHeaders($this->basicAuth(username: 'someone@else.com')))
            ->assertStatus(401);
    }

    public function test_an_unusable_stored_hash_answers_401_rather_than_500(): void
    {
        // A mangled hash in .env once produced exactly this pair of symptoms:
        // unauthenticated requests 401'd correctly while every authenticated one
        // 500'd, because Hash::check throws on a non-bcrypt value.
        DB::table('users')->update(['password' => 'not-a-bcrypt-hash']);

        $this->call('PROPFIND', '/dav', server: $this->serverHeaders($this->basicAuth()))
            ->assertStatus(401);
    }

    public function test_the_address_is_matched_case_insensitively(): void
    {
        // iOS capitalises the first letter of a text field by default. sabre
        // builds the principal from the username as typed, so without
        // canonicalisation this authenticates and then addresses nothing.
        $response = $this->propfind('/dav', 0, '<d:prop><d:current-user-principal/></d:prop>',
            $this->basicAuth(username: 'Michal@Skoula.com'));

        $response->assertStatus(207);
        $this->assertStringContainsString($this->principalPath('/'), $response->getContent());
    }

    public function test_the_discovery_chain_resolves(): void
    {
        $root = $this->propfind('/dav', 0, '<d:prop><d:current-user-principal/></d:prop>');
        $root->assertStatus(207);
        $this->assertStringContainsString($this->principalPath('/'), $root->getContent());

        $principal = $this->propfind($this->principalPath(), 0,
            '<d:prop><c:calendar-home-set/></d:prop>');
        $principal->assertStatus(207);
        $this->assertStringContainsString($this->calendarHome('/'), $principal->getContent());
    }

    public function test_the_calendar_advertises_vevent_only(): void
    {
        $home = $this->propfind($this->calendarHome(), 1,
            '<d:prop><d:displayname/><c:supported-calendar-component-set/></d:prop>');

        $home->assertStatus(207);
        $body = $home->getContent();

        $this->assertStringContainsString($this->calendarHome('/tasks/'), $body);
        $this->assertStringContainsString('name="VEVENT"', $body);
        $this->assertStringNotContainsString('name="VTODO"', $body);
    }

    public function test_the_scheduling_collections_enumerate_instead_of_erroring(): void
    {
        // The stock backend implements SchedulingSupport, so Apple PROPFINDs an
        // inbox and an outbox while adding the account. Both must answer.
        foreach (['inbox', 'outbox'] as $collection) {
            $this->propfind($this->calendarHome("/{$collection}"), 1, '<d:prop><d:resourcetype/></d:prop>')
                ->assertStatus(207);
        }
    }

    public function test_well_known_redirects_for_both_get_and_propfind(): void
    {
        $this->get('/.well-known/caldav')->assertRedirect('/dav');

        // RFC 6764 clients use PROPFIND here, and a CalDAV client has no CSRF token.
        $this->call('PROPFIND', '/.well-known/caldav')->assertRedirect('/dav');
    }

    public function test_a_put_over_http_creates_a_task_and_a_delete_returns_it_to_the_inbox(): void
    {
        $put = $this->call(
            'PUT',
            $this->calendarHome('/tasks/over-http.ics'),
            server: $this->serverHeaders($this->basicAuth() + ['Content-Type' => 'text/calendar; charset=utf-8']),
            content: $this->appleEvent('over-http', ['SUMMARY' => 'Napsat Barče']),
        );

        $put->assertStatus(201);
        $this->assertNotEmpty($put->headers->get('ETag'));

        $task = Task::where('ical_uid', 'over-http')->sole();
        $this->assertSame(Task::STATUS_SCHEDULED, $task->status);
        $this->assertSame('Napsat Barče', $task->title);

        $this->call('DELETE', $this->calendarHome('/tasks/over-http.ics'),
            server: $this->serverHeaders($this->basicAuth()))->assertStatus(204);

        $task->refresh();
        $this->assertSame(Task::STATUS_INBOX, $task->status);
        $this->assertNull($task->ical_uid);
    }

    public function test_a_sync_collection_report_sees_a_change(): void
    {
        $this->backend()->createCalendarObject($this->calendarId(), 'sync-me.ics', $this->appleEvent('sync-me'));

        $report = $this->call(
            'REPORT',
            $this->calendarHome('/tasks'),
            server: $this->serverHeaders($this->basicAuth() + ['Depth' => '1', 'Content-Type' => 'application/xml']),
            content: '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token/>'
                .'<d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>',
        );

        $report->assertStatus(207);
        $this->assertStringContainsString('sync-me.ics', $report->getContent());
    }

    public function test_options_advertises_calendar_access(): void
    {
        $response = $this->call('OPTIONS', $this->calendarHome('/tasks'),
            server: $this->serverHeaders($this->basicAuth()));

        $this->assertStringContainsString('calendar-access', (string) $response->headers->get('DAV'));
    }

    /** @param  array<string, string>|null  $auth */
    private function propfind(string $uri, int $depth, string $prop, ?array $auth = null): TestResponse
    {
        return $this->call(
            'PROPFIND',
            $uri,
            server: $this->serverHeaders(($auth ?? $this->basicAuth()) + ['Depth' => (string) $depth, 'Content-Type' => 'application/xml']),
            content: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
                .$prop.'</d:propfind>',
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
