<?php

namespace App\Feeds;

/**
 * String work on a feed URL. No I/O lives here — FeedGuard does the resolving,
 * because that is the part a test has to be able to replace.
 */
final class FeedUrl
{
    /**
     * Address ranges this server will not fetch from.
     *
     * The container shares a compose bridge with Caddy, n8n and OpenWebUI, and
     * the box itself is on a tailnet, so a pasted URL is a way to ask the server
     * to fetch things the person typing it cannot reach. Carrier-grade NAT
     * (100.64/10) is in the list because that is where Tailscale addresses live.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],         // this host
        ['10.0.0.0', 8],        // private
        ['100.64.0.0', 10],     // CGNAT, and the tailnet
        ['127.0.0.0', 8],       // loopback
        ['169.254.0.0', 16],    // link-local, and cloud metadata
        ['172.16.0.0', 12],     // private, and the docker bridges
        ['192.0.0.0', 24],      // IETF protocol assignments
        ['192.168.0.0', 16],    // private
        ['198.18.0.0', 15],     // benchmarking
        ['224.0.0.0', 4],       // multicast
        ['240.0.0.0', 4],       // reserved
    ];

    /**
     * Fold a typed URL into the one canonical form stored and hashed.
     *
     * `webcal://` is what Apple and Google hand out on their share buttons; it
     * is an https URL wearing a scheme no HTTP client knows.
     */
    public static function normalise(string $input): string
    {
        $url = trim($input);

        if ($url === '') {
            throw new FeedRefused('Give the calendar a URL.');
        }

        $url = preg_replace('#^webcals?://#i', 'https://', $url) ?? $url;

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new FeedRefused('That does not look like a URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, self::allowedSchemes(), true)) {
            throw new FeedRefused('Only https calendar URLs can be subscribed to.');
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $user = isset($parts['user']) ? rawurlencode($parts['user']) : '';
        $pass = isset($parts['pass']) ? ':'.rawurlencode($parts['pass']) : '';
        $auth = $user === '' ? '' : $user.$pass.'@';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        // The fragment goes: it never reaches the server, so two URLs differing
        // only there are the same subscription.
        return $scheme.'://'.$auth.$host.$port.$path.$query;
    }

    /** @return array<int, string> */
    public static function allowedSchemes(): array
    {
        return config('feeds.allow_private') ? ['https', 'http'] : ['https'];
    }

    public static function hostOf(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /** A name this feed can be given before anyone renames it. */
    public static function suggestName(string $url): string
    {
        $host = self::hostOf($url);

        return $host === '' ? 'Calendar' : preg_replace('/^www\./', '', $host);
    }

    /**
     * Why this address may not be fetched, or null if it may.
     *
     * Returns a reason rather than a boolean so the refusal can say which range
     * it landed in, which is the difference between a useful error and "no".
     */
    public static function blockedReason(string $ip): ?string
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return 'that host does not resolve to an address';
        }

        if (strlen($binary) === 16) {
            // An IPv4-mapped address is an IPv4 address with a prefix; judge it
            // as one, or ::ffff:127.0.0.1 walks straight past the v4 list.
            if (str_starts_with($binary, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
                return self::blockedReason(inet_ntop(substr($binary, 12)) ?: $ip);
            }

            return self::blockedV6($binary);
        }

        $long = ip2long($ip);

        if ($long === false) {
            return 'that host does not resolve to an address';
        }

        foreach (self::BLOCKED_V4 as [$network, $bits]) {
            $mask = -1 << (32 - $bits);

            if ((($long & $mask) === (ip2long($network) & $mask))) {
                return "it resolves to {$ip}, inside {$network}/{$bits}, which is on this network rather than the internet";
            }
        }

        return null;
    }

    private static function blockedV6(string $binary): ?string
    {
        $ip = inet_ntop($binary) ?: '::';

        // ::  and ::1
        if ($binary === str_repeat("\x00", 16) || $binary === str_repeat("\x00", 15)."\x01") {
            return "it resolves to {$ip}, which is this host";
        }

        $first = ord($binary[0]);

        if (($first & 0xFE) === 0xFC) {
            return "it resolves to {$ip}, a unique-local address on this network";
        }

        if ($first === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) {
            return "it resolves to {$ip}, a link-local address";
        }

        if ($first === 0xFF) {
            return "it resolves to {$ip}, a multicast address";
        }

        return null;
    }
}
