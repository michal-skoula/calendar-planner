<?php

namespace Tests\Unit;

use App\Feeds\FeedGuard;
use App\Feeds\FeedRefused;
use App\Feeds\FeedUrl;
use Tests\TestCase;

class FeedUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['feeds.allow_private' => false]);
    }

    public function test_webcal_links_become_https(): void
    {
        $this->assertSame(
            'https://p01.calendar.icloud.com/published/2/abc',
            FeedUrl::normalise('webcal://p01.calendar.icloud.com/published/2/abc'),
        );
    }

    public function test_a_bare_host_gets_https(): void
    {
        $this->assertSame('https://example.com/cal.ics', FeedUrl::normalise('example.com/cal.ics'));
    }

    public function test_the_host_is_lowercased_but_the_path_is_not(): void
    {
        // The path and query carry the secret, and providers do mint
        // case-sensitive tokens, so only the host may be folded.
        $this->assertSame(
            'https://calendar.google.com/calendar/ical/AbC123/basic.ics',
            FeedUrl::normalise('https://Calendar.Google.COM/calendar/ical/AbC123/basic.ics'),
        );
    }

    public function test_the_fragment_is_dropped_so_two_such_urls_are_one_subscription(): void
    {
        $this->assertSame(
            FeedUrl::normalise('https://example.com/cal.ics'),
            FeedUrl::normalise('https://example.com/cal.ics#work'),
        );
    }

    public function test_plain_http_is_refused_in_production(): void
    {
        $this->expectException(FeedRefused::class);
        FeedUrl::normalise('http://example.com/cal.ics');
    }

    public function test_other_schemes_are_refused(): void
    {
        $this->expectException(FeedRefused::class);
        FeedUrl::normalise('file:///etc/passwd');
    }

    /**
     * The point of the whole guard: this container sits on a compose bridge with
     * Caddy, n8n and OpenWebUI, and the box is on a tailnet.
     */
    public function test_addresses_on_this_network_are_refused(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.17.0.2', '192.168.1.1', '169.254.169.254', '100.101.102.103', '0.0.0.0'] as $ip) {
            $this->assertNotNull(FeedUrl::blockedReason($ip), "{$ip} should be refused");
        }
    }

    public function test_public_addresses_are_allowed(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946'] as $ip) {
            $this->assertNull(FeedUrl::blockedReason($ip), "{$ip} should be allowed");
        }
    }

    public function test_ipv6_loopback_and_local_addresses_are_refused(): void
    {
        foreach (['::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1'] as $ip) {
            $this->assertNotNull(FeedUrl::blockedReason($ip), "{$ip} should be refused");
        }
    }

    public function test_a_host_resolving_into_this_network_is_refused(): void
    {
        $guard = new FeedGuard(fn (string $host): array => ['10.0.0.7']);

        $this->expectException(FeedRefused::class);
        $this->expectExceptionMessageMatches('/inside 10\.0\.0\.0\/8/');

        $guard->assert('https://looks-fine.example.com/cal.ics');
    }

    public function test_a_host_resolving_to_several_addresses_is_refused_if_any_is_local(): void
    {
        $guard = new FeedGuard(fn (string $host): array => ['93.184.216.34', '127.0.0.1']);

        $this->expectException(FeedRefused::class);

        $guard->assert('https://split-horizon.example.com/cal.ics');
    }

    public function test_a_host_that_does_not_resolve_is_refused(): void
    {
        $guard = new FeedGuard(fn (string $host): array => []);

        $this->expectException(FeedRefused::class);

        $guard->assert('https://nowhere.example/cal.ics');
    }

    public function test_a_public_host_passes_and_comes_back_normalised(): void
    {
        $guard = new FeedGuard(fn (string $host): array => ['93.184.216.34']);

        $this->assertSame(
            'https://example.com/cal.ics',
            $guard->assert('webcal://Example.com/cal.ics#x'),
        );
    }
}
