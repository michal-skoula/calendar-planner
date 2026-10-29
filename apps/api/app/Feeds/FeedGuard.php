<?php

namespace App\Feeds;

/**
 * Decides whether a URL may be fetched at all.
 *
 * It runs twice per fetch: once before the request, and again on every redirect
 * hop, because a provider whose link has gone stale may 302 anywhere and the
 * second hop is the one that would reach the compose bridge.
 *
 * There is a window between resolving a name here and the HTTP client resolving
 * it again when it connects, so a name that answers with two different addresses
 * can get past this. Closing it properly means pinning the connection to the
 * address that was checked. For one person's own URLs on a tailnet that is more
 * machinery than the risk is worth, and it is recorded in the plan rather than
 * pretended away.
 *
 * The resolver is injected so tests do not need working DNS.
 */
final class FeedGuard
{
    /** @var callable(string): array<int, string> */
    private $resolve;

    /** @param null|callable(string): array<int, string> $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolve = $resolver ?? self::dns(...);
    }

    /**
     * Normalise, then refuse anything that is not out on the internet.
     *
     * @return string the normalised URL, which is what gets stored and fetched
     *
     * @throws FeedRefused
     */
    public function assert(string $url): string
    {
        $normalised = FeedUrl::normalise($url);

        $this->assertReachableOnly($normalised);

        return $normalised;
    }

    /**
     * The same check against an already-normalised URL. Used for redirect hops,
     * where the scheme has already been constrained by the HTTP client.
     *
     * @throws FeedRefused
     */
    public function assertReachableOnly(string $url): void
    {
        if (config('feeds.allow_private')) {
            return;
        }

        $host = FeedUrl::hostOf($url);

        if ($host === '') {
            throw new FeedRefused('That URL has no host.');
        }

        $addresses = ($this->resolve)(trim($host, '[]'));

        if ($addresses === []) {
            throw new FeedRefused("Could not look up {$host}.");
        }

        foreach ($addresses as $address) {
            $reason = FeedUrl::blockedReason($address);

            if ($reason !== null) {
                throw new FeedRefused("Refusing to fetch {$host}: {$reason}.");
            }
        }
    }

    /** @return array<int, string> */
    private static function dns(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
