<?php

namespace App\Dav;

use Illuminate\Support\Facades\Log;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Writes one line per DAV request to the `dav` log channel.
 *
 * Without this a failing device is close to undebuggable: sabre answers with an
 * XML error the device never shows you, and iOS reports the whole thing as an
 * inactive account. Here you get the method, the path, who sabre thinks you are,
 * and the status it answered with.
 *
 * It never logs credentials — only the authentication scheme offered.
 */
final class DavLogPlugin extends ServerPlugin
{
    private ?Server $server = null;

    private float $startedAt = 0.0;

    public function initialize(Server $server): void
    {
        $this->server = $server;

        // Priority 1 so this runs before authentication, which is what makes an
        // unauthenticated attempt visible at all.
        $server->on('beforeMethod:*', [$this, 'onBeforeMethod'], 1);
        $server->on('afterMethod:*', [$this, 'onAfterMethod'], 1000);
        $server->on('exception', [$this, 'onException']);
    }

    public function getPluginName(): string
    {
        return 'planner-log';
    }

    public function onBeforeMethod(RequestInterface $request, ResponseInterface $response): void
    {
        $this->startedAt = microtime(true);
    }

    public function onAfterMethod(RequestInterface $request, ResponseInterface $response): void
    {
        $status = $response->getStatus();
        $context = [
            'method' => $request->getMethod(),
            'path' => $request->getUrl(),
            'status' => $status,
            'depth' => $request->getHeader('Depth') ?? '-',
            'principal' => $this->currentPrincipal() ?? '-',
            'auth' => $this->authScheme($request),
            'agent' => $this->shortAgent($request),
            'ms' => $this->elapsedMs(),
        ];

        $line = 'DAV {method} {path} -> {status} (principal={principal} auth={auth} agent={agent} {ms}ms)';

        // 401 is routine: every client makes one unauthenticated probe before it
        // offers credentials. A repeated 401 is the interesting case, and you can
        // see that from the sequence.
        match (true) {
            $status >= 500 => Log::channel('dav')->error($line, $context),
            $status >= 400 && $status !== 401 => Log::channel('dav')->warning($line, $context),
            default => Log::channel('dav')->info($line, $context),
        };
    }

    public function onException(\Throwable $e): void
    {
        // Every client makes an unauthenticated probe before it offers
        // credentials, so NotAuthenticated is the normal first exchange rather
        // than a fault. Logging it as an error buries the real ones.
        $routine = $e instanceof NotAuthenticated;

        Log::channel('dav')->log($routine ? 'info' : 'error', 'DAV exception {class}: {message}', [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]);
    }

    private function currentPrincipal(): ?string
    {
        $auth = $this->server?->getPlugin('auth');

        return $auth instanceof AuthPlugin ? $auth->getCurrentPrincipal() : null;
    }

    /** The scheme offered, never the credentials themselves. */
    private function authScheme(RequestInterface $request): string
    {
        $header = (string) $request->getHeader('Authorization');

        if ($header === '') {
            return 'none';
        }

        return strtolower(strtok($header, ' ') ?: 'unknown');
    }

    private function shortAgent(RequestInterface $request): string
    {
        $agent = (string) $request->getHeader('User-Agent');

        if ($agent === '') {
            return '-';
        }

        // "iOS/26.1 (23B5044e) dataaccessd/1.0" -> "iOS/26.1"
        return strtok($agent, ' ') ?: '-';
    }

    private function elapsedMs(): int
    {
        return $this->startedAt > 0.0 ? (int) round((microtime(true) - $this->startedAt) * 1000) : 0;
    }
}
